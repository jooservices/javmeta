<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Events\SettingsApplied;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Env;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Support\ConfigType;
use Throwable;

/**
 * The settings the owner edits in the UI. Values live in the laravel-config store (group "settings"); `apply()` pushes
 * them into the Laravel config so existing code keeps reading config(). A key that was never saved keeps the config
 * default, so nothing breaks before `settings:import-env` has run.
 */
final class SettingsService
{
    public const GROUP = 'settings';

    /** @var array<string, mixed>|null the config values before any database value was applied */
    private static ?array $defaults = null;

    public function __construct(private readonly ConfigStore $store) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return (array) config('javmeta_settings.definitions', []);
    }

    /** @return array<string, string> */
    public function groups(): array
    {
        return (array) config('javmeta_settings.groups', []);
    }

    /** Push every saved value into the Laravel config. Safe when the store is down: the defaults stay. */
    public function apply(): bool
    {
        try {
            $saved = $this->store->group(self::GROUP);
        } catch (Throwable) {
            return false;
        }

        $this->captureDefaults();
        $applied = [];
        foreach ($this->definitions() as $key => $definition) {
            $isSaved = array_key_exists($key, $saved);
            [$fallback, $source] = $isSaved ? [null, 'database'] : $this->fallbackValue($key, $definition);
            if (! $isSaved && $source !== 'environment') {
                continue;
            }
            $value = $this->effectiveValue($key, $definition, $isSaved ? $this->fromStorage($definition, $saved[$key]) : $fallback);
            if ($this->urlWarning($key, $definition, $value) !== null) {
                $value = $this->default($key);
            }
            foreach ((array) $definition['config'] as $configKey) {
                config([$configKey => $value]);
            }
            $applied[] = $key;
        }
        if ($applied !== []) {
            event(new SettingsApplied($applied));
        }

        return true;
    }

    /** Force a settings-store read before applying settings used to make startup decisions. */
    public function applyFresh(): bool
    {
        try {
            $this->store->refresh();
        } catch (Throwable) {
            return false;
        }

        return $this->apply();
    }

    /** Restore the code defaults if a previously loaded settings store becomes unavailable. */
    public function restoreDefaults(): void
    {
        $this->captureDefaults();
        foreach ($this->definitions() as $key => $definition) {
            foreach ((array) $definition['config'] as $configKey) {
                config([$configKey => self::$defaults[$key] ?? null]);
            }
        }
    }

    /**
     * Everything the Settings page shows. A secret is never returned: only whether it is set.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $this->captureDefaults();
        try {
            $saved = $this->store->group(self::GROUP);
        } catch (Throwable) {
            $saved = [];
        }

        $rows = [];
        foreach ($this->definitions() as $key => $definition) {
            $isSecret = $definition['type'] === 'secret';
            $inDatabase = array_key_exists($key, $saved);
            [$fallback, $source] = $inDatabase ? [null, 'database'] : $this->fallbackValue($key, $definition);
            $current = $this->effectiveValue($key, $definition, $inDatabase ? $this->fromStorage($definition, $saved[$key]) : $fallback);
            $warning = $this->urlWarning($key, $definition, $current);
            if ($warning !== null) {
                $current = $this->default($key);
                $inDatabase = false;
                $source = 'default';
            }
            $rows[] = [
                'key' => $key,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'options' => $definition['options'] ?? null,
                'restart' => $definition['restart'] ?? null,
                'apply_after_seconds' => (int) ($definition['apply_after_seconds'] ?? 30),
                'shape' => $definition['shape'] ?? null,
                'max_length' => (int) config('javmeta_settings.validation.max_text_length', 500),
                'source' => $inDatabase ? 'database' : $source,
                'value' => $isSecret ? null : $current,
                'is_set' => $isSecret ? (is_string($current) && $current !== '') : null,
                'warning' => $warning,
            ];
        }

        return $rows;
    }

    /**
     * Validate and save one setting. An empty secret clears it.
     *
     * @return array{key: string, old: mixed, new: mixed, secret: bool, apply_after_seconds: int}
     */
    public function set(string $key, mixed $value): array
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
        $clean = $this->validate($key, $definition, $value);
        $this->assertTimeoutRelationships($key, $clean);
        if (in_array($key, ['capture.max_duration_sec', 'media.job_timeout_sec', 'media.lease_seconds'], true)) {
            $duration = $key === 'capture.max_duration_sec' ? $clean : $this->get('capture.max_duration_sec');
            $timeout = $key === 'media.job_timeout_sec' ? $clean : $this->get('media.job_timeout_sec');
            $lease = $key === 'media.lease_seconds' ? $clean : $this->get('media.lease_seconds');
            if ($duration > min($timeout, $lease - 10)) {
                throw new InvalidArgumentException("{$key}: capture duration must fit within min(media.job_timeout_sec, media.lease_seconds - 10).");
            }
        }
        $isSecret = $definition['type'] === 'secret';

        $this->captureDefaults();
        $old = $this->current($key, $definition);
        $path = self::GROUP . '.' . $key;
        $this->store->set($path, $this->toStorage($definition, $clean), $this->storageType($definition));
        foreach ((array) $definition['config'] as $configKey) {
            config([$configKey => $clean]);
        }
        event(new SettingsApplied([$key]));

        if (isset($definition['url_schemes'])) {
            $old = SettingUrl::redact((string) $old);
            $clean = SettingUrl::redact((string) $clean);
        }

        return [
            'key' => $key,
            'old' => $isSecret ? null : $old,
            'new' => $isSecret ? null : $clean,
            'secret' => $isSecret,
            'apply_after_seconds' => (int) ($definition['apply_after_seconds'] ?? 30),
        ];
    }

    /** @return list<array{key: string, value: mixed, display: mixed, source: string}> */
    public function importPreview(): array
    {
        $this->captureDefaults();
        $saved = $this->store->group(self::GROUP);
        $rows = [];
        foreach ($this->definitions() as $key => $definition) {
            if (array_key_exists($key, $saved)) {
                continue;
            }
            $source = 'default';
            $raw = $this->legacyValue($definition);
            if ($raw !== null) {
                $source = 'environment';
            }
            $value = $raw ?? $this->default($key);
            if ($value === null || $value === '') {
                continue;
            }
            try {
                $value = $this->validate($key, $definition, $value);
            } catch (InvalidArgumentException) {
                $value = $this->default($key);
                $source = 'default';
            }
            if ($value === null || $value === '') {
                continue;
            }
            $rows[] = [
                'key' => $key,
                'value' => $value,
                'display' => $definition['type'] === 'secret' ? '[redacted]' : $value,
                'source' => $source,
            ];
        }

        return $rows;
    }

    /** Copy the current effective settings into the database once; returns the keys written. */
    public function importDefaults(): array
    {
        $entries = [];
        foreach ($this->importPreview() as $row) {
            $definition = $this->definitions()[$row['key']];
            $entries[self::GROUP . '.' . $row['key']] = [
                'value' => $this->toStorage($definition, $row['value']),
                'type' => $this->storageType($definition),
            ];
        }

        if ($entries === []) {
            return [];
        }

        $attempted = [];
        try {
            foreach ($entries as $path => $entry) {
                $attempted[] = $path;
                $this->store->set($path, $entry['value'], $entry['type']);
            }
        } catch (Throwable $error) {
            try {
                $this->store->forgetMany($attempted);
            } catch (Throwable) {
                throw new RuntimeException('Settings import failed and its partial writes could not be removed; inspect the settings store before retrying.', previous: $error);
            }

            throw $error;
        }

        return array_map(static fn(string $path): string => substr($path, strlen(self::GROUP) + 1), array_keys($entries));
    }

    public function get(string $key): mixed
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");

        return $this->current($key, $definition);
    }

    /** @param array<string, mixed> $definition */
    private function current(string $key, array $definition): mixed
    {
        try {
            $saved = $this->store->group(self::GROUP);
        } catch (Throwable) {
            $saved = [];
        }

        $inDatabase = array_key_exists($key, $saved);
        [$fallback] = $inDatabase ? [null, 'database'] : $this->fallbackValue($key, $definition);
        $value = $this->effectiveValue($key, $definition, $inDatabase ? $this->fromStorage($definition, $saved[$key]) : $fallback);

        return $this->urlWarning($key, $definition, $value) === null ? $value : $this->default($key);
    }

    /** @param array<string, mixed> $definition */
    private function effectiveValue(string $key, array $definition, mixed $value): mixed
    {
        if ($key === 'ai.nl_timeout_seconds' && is_numeric($value)) {
            // Persisted timeout settings must remain within the current request deadline.
            return min((int) ($definition['max'] ?? $value), max((int) ($definition['min'] ?? 1), (int) $value));
        }

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function urlWarning(string $key, array $definition, mixed $value): ?string
    {
        if (! isset($definition['url_schemes'])) {
            return null;
        }
        try {
            SettingUrl::validate($key, $definition, (string) $value);
        } catch (InvalidArgumentException) {
            return 'URL đã lưu không hợp lệ và đang được bỏ qua. Vui lòng lưu địa chỉ không chứa thông tin đăng nhập, truy vấn hoặc fragment.';
        }

        return null;
    }

    private function default(string $key): mixed
    {
        $this->captureDefaults();

        $value = self::$defaults[$key] ?? null;

        // A legacy default may contain the same unsafe URL as the ignored database value.
        return $this->urlWarning($key, $this->definitions()[$key], $value) === null ? $value : '';
    }

    /** @param array<string, mixed> $definition
     *  @return array{mixed, string}
     */
    private function fallbackValue(string $key, array $definition): array
    {
        $legacy = $this->legacyValue($definition);
        if ($legacy === null) {
            return [$this->default($key), 'default'];
        }

        try {
            return [$this->validate($key, $definition, $legacy), 'environment'];
        } catch (InvalidArgumentException) {
            return [$this->default($key), 'default'];
        }
    }

    private function captureDefaults(): void
    {
        if (self::$defaults !== null) {
            return;
        }
        self::$defaults = [];
        foreach ($this->definitions() as $key => $definition) {
            // The first config key that holds a value: a setting may drive several keys and env fills only some of them.
            $value = null;
            foreach ((array) $definition['config'] as $configKey) {
                $candidate = config($configKey);
                if ($candidate !== null && $candidate !== '') {
                    $value = $candidate;
                    break;
                }
                $value ??= $candidate;
            }
            self::$defaults[$key] = $value;
        }
    }

    public static function forgetDefaults(): void
    {
        self::$defaults = null;
    }

    /** @param array<string, mixed> $definition */
    private function validate(string $key, array $definition, mixed $value): mixed
    {
        $type = $definition['type'];
        $fail = static fn(string $why): never => throw new InvalidArgumentException("{$key}: {$why}");

        if (($definition['nullable'] ?? false) && ($value === '' || $value === null)) {
            return null;
        }

        if ($type === 'bool') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return $bool ?? $fail('must be true or false');
        }
        if ($type === 'int' || $type === 'float') {
            if (! is_numeric($value)) {
                $fail('must be a number');
            }
            $number = $type === 'int' ? (int) $value : (float) $value;
            if ($type === 'int' && (string) (int) $value !== (string) $value && ! is_int($value) && ! (is_float($value) && floor($value) === $value)) {
                $fail('must be a whole number');
            }
            if (isset($definition['min']) && $number < $definition['min']) {
                $fail('must be at least ' . $definition['min']);
            }
            if (isset($definition['max']) && $number > $definition['max']) {
                $fail('must be at most ' . $definition['max']);
            }
            if (isset($definition['options']) && ! in_array($number, $definition['options'], true)) {
                $fail('must be one of ' . implode(', ', $definition['options']));
            }

            return $number;
        }
        if ($type === 'string_list') {
            $items = $this->validateStringList($key, $value, $fail);
            foreach ($items as $item) {
                if (isset($definition['item_options']) && ! in_array($item, $definition['item_options'], true)) {
                    $fail('entries must be one of ' . implode(', ', $definition['item_options']));
                }
            }

            return $items;
        }
        if ($type === 'int_list') {
            return $this->validateIntList($value, $fail);
        }
        if ($type === 'json') {
            return $this->validateJson($key, $definition, $value, $fail);
        }
        // string and secret
        if (! is_string($value) && ! is_numeric($value)) {
            $fail('must be text');
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > (int) config('javmeta_settings.validation.max_text_length', 500)) {
            $fail('is too long');
        }
        if (isset($definition['options']) && ! in_array($text, $definition['options'], true)) {
            $fail('must be one of ' . implode(', ', $definition['options']));
        }
        if (isset($definition['url_schemes'])) {
            SettingUrl::validate($key, $definition, $text);
        }

        return $text;
    }

    /** @param callable(string): never $fail
     *  @return list<string>
     */
    private function validateStringList(string $key, mixed $value, callable $fail): array
    {
        $parts = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
        $items = array_values(array_unique(array_filter(array_map(static fn($part): string => trim((string) $part), $parts ?: []), static fn(string $part): bool => $part !== '')));
        if ($key === 'worker.queue_names') {
            $items = $this->normalizeQueueNames($items, $fail);
        }
        foreach ($items as $item) {
            if (mb_strlen($item) > (int) config('javmeta_settings.validation.max_text_length', 500)) {
                $fail('an entry is too long');
            }
        }
        if (count($items) > (int) config('javmeta_settings.validation.max_list_items', 100)) {
            $fail('has too many entries');
        }

        return $items;
    }

    /** @param list<string> $items
     *  @param callable(string): never $fail
     *  @return list<string>
     */
    private function normalizeQueueNames(array $items, callable $fail): array
    {
        if ($items === []) {
            $fail('needs at least one queue name');
        }
        if (array_filter($items, static fn(string $item): bool => preg_match('/^[A-Za-z0-9_]+$/', $item) !== 1) !== []) {
            $fail('queue names may contain only letters, numbers, and underscores');
        }

        $crawlQueues = array_values((array) config('javmeta_queues.names', []));
        $browserQueues = array_values((array) config('javmeta_queues.browser_names', []));
        foreach ($crawlQueues as $index => $queue) {
            $browserQueue = $browserQueues[$index] ?? null;
            if (in_array($queue, $items, true) && is_string($browserQueue) && ! in_array($browserQueue, $items, true)) {
                $items[] = $browserQueue;
            }
        }

        return $items;
    }

    /** @param callable(string): never $fail
     *  @return list<int>
     */
    private function validateIntList(mixed $value, callable $fail): array
    {
        $parts = is_array($value) ? $value : explode(',', (string) $value);
        $numbers = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            if (! ctype_digit($part) || (int) $part < 1) {
                $fail('must be positive whole numbers separated by commas');
            }
            $numbers[] = (int) $part;
        }
        if (count($numbers) > (int) config('javmeta_settings.validation.max_list_items', 100)) {
            $fail('has too many entries');
        }

        return $numbers === [] ? $fail('needs at least one number') : $numbers;
    }

    /** @param array<string, mixed> $definition
     *  @param callable(string): never $fail
     *  @return array<string, mixed>
     */
    private function validateJson(string $key, array $definition, mixed $value, callable $fail): array
    {
        if (is_string($value)) {
            if (! str_starts_with(ltrim($value), '{')) {
                $fail('must be a JSON object');
            }
            try {
                $value = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $fail('must be valid JSON');
            }
        }
        if (! is_array($value) || (array_is_list($value) && $value !== [])) {
            $fail('must be a JSON object');
        }
        if (count($value) > (int) config('javmeta_settings.validation.max_json_entries', 50)) {
            $fail('has too many entries');
        }
        $this->validateJsonShape($key, (string) ($definition['shape'] ?? ''), $value, $fail);

        return $value;
    }

    /** @param array<string, mixed> $definition */
    private function toStorage(array $definition, mixed $value): mixed
    {
        if ($definition['nullable'] ?? false) {
            return $value === null ? '' : (string) $value;
        }
        return match ($definition['type']) {
            'int_list' => implode(',', (array) $value),
            'string_list' => implode("\n", (array) $value),
            'json' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            default => $value,
        };
    }

    /** @param array<string, mixed> $definition */
    private function fromStorage(array $definition, mixed $stored): mixed
    {
        if (($definition['nullable'] ?? false) && ($stored === null || $stored === '')) {
            return null;
        }
        return match ($definition['type']) {
            'int' => (int) $stored,
            'float' => (float) $stored,
            'bool' => filter_var($stored, FILTER_VALIDATE_BOOLEAN),
            'int_list' => array_values(array_filter(array_map('intval', explode(',', (string) $stored)), static fn(int $n): bool => $n > 0)),
            'string_list' => array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $stored) ?: []), static fn(string $p): bool => $p !== '')),
            'json' => json_decode((string) $stored, true, 32, JSON_THROW_ON_ERROR),
            default => (string) $stored,
        };
    }

    /** @param array<string, mixed> $definition */
    private function storageType(array $definition): string
    {
        if ($definition['nullable'] ?? false) {
            return ConfigType::String->value;
        }
        return match ($definition['type']) {
            'int' => ConfigType::Int->value,
            'float' => ConfigType::Float->value,
            'bool' => ConfigType::Bool->value,
            'secret' => ConfigType::Encrypted->value,
            default => ConfigType::String->value,
        };
    }

    /** @param array<array-key, mixed> $value
     *  @param callable(string): never $fail
     */
    private function validateJsonShape(string $key, string $shape, array $value, callable $fail): void
    {
        if ($shape === 'queue_worker_map') {
            $queues = (array) config('javmeta_queues.horizon_names', []);
            foreach ($value as $queue => $workers) {
                if (! is_string($queue) || ! in_array($queue, $queues, true) || ! is_int($workers) || $workers < 1 || $workers > 32) {
                    $fail('must map known queue names to worker counts from 1 to 32');
                }
            }

            return;
        }
        if ($shape !== 'node_worker_map') {
            $fail('has an unsupported JSON shape');
        }
        foreach ($value as $node => $overrides) {
            if (! is_string($node) || ! preg_match('/^(main|worker)(?:@[A-Za-z0-9._-]+)?$/', $node) || ! is_array($overrides) || array_is_list($overrides)) {
                $fail('must map main, worker, or role@hostname to worker overrides');
            }
            foreach ($overrides as $name => $workers) {
                if ($name === 'queues') {
                    if (! is_array($workers) || array_is_list($workers)) {
                        $fail('node queues must be an object of known queues and worker counts');
                    }
                    foreach ($workers as $queue => $count) {
                        if (! is_string($queue) || ! in_array($queue, (array) config('javmeta_queues.horizon_names', []), true) || ! is_int($count) || $count < 1 || $count > 32) {
                            $fail('node queue overrides must use known queues and worker counts from 1 to 32');
                        }
                    }
                    continue;
                }
                if (! in_array($name, ['default', 'direct', 'browser'], true) || ! is_int($workers) || $workers < 1 || $workers > 32) {
                    $fail('node overrides may define default, direct, and browser worker counts from 1 to 32');
                }
            }
        }
    }

    private function legacyValue(array $definition): mixed
    {
        foreach ((array) ($definition['legacy_env'] ?? []) as $name) {
            $value = Env::get((string) $name);
            if ($value !== false && $value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param list<string> $keys */
    public function applyAfterSeconds(array $keys): int
    {
        $definitions = $this->definitions();
        $seconds = array_map(static fn(string $key): int => (int) ($definitions[$key]['apply_after_seconds'] ?? 30), $keys);

        return $seconds === [] ? 0 : max($seconds);
    }

    private function assertTimeoutRelationships(string $key, mixed $value): void
    {
        $candidate = fn(string $setting): mixed => $setting === $key ? $value : $this->get($setting);
        $fetchClient = (int) $candidate('queue.worker_timeout');
        $fetchService = (int) $candidate('timeouts.fetch_service_seconds');
        if ($fetchClient <= $fetchService) {
            throw new InvalidArgumentException('queue.worker_timeout must exceed timeouts.fetch_service_seconds.');
        }

        $retryAfter = (int) $candidate('queue.retry_after_seconds');
        if ($retryAfter <= $fetchClient) {
            throw new InvalidArgumentException('queue.retry_after_seconds must exceed queue.worker_timeout.');
        }

        $gatewayClient = (int) $candidate('browser.gateway_client_timeout_ms');
        $gatewayWait = (int) $candidate('browser.gateway_wait_timeout_ms');
        $playwright = (int) $candidate('browser.playwright_request_timeout_ms');
        $flareSolverr = (int) $candidate('browser.flaresolverr_max_timeout_ms');
        if ($gatewayWait <= max($playwright, $flareSolverr)) {
            throw new InvalidArgumentException('browser.gateway_wait_timeout_ms must exceed Playwright and FlareSolverr service timeouts.');
        }
        if ($gatewayClient <= $gatewayWait) {
            throw new InvalidArgumentException('browser.gateway_client_timeout_ms must exceed browser.gateway_wait_timeout_ms.');
        }

        $dispatchWarning = (int) $candidate('alerts.crawl_dispatch_warning_seconds');
        $dispatchCritical = (int) $candidate('alerts.crawl_dispatch_critical_seconds');
        if ($dispatchCritical < $dispatchWarning) {
            throw new InvalidArgumentException('alerts.crawl_dispatch_critical_seconds must be at least alerts.crawl_dispatch_warning_seconds.');
        }
    }
}
