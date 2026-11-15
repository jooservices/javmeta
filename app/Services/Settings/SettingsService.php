<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Services\Api\ResponseCacheEpoch;
use App\Events\SettingsApplied;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Log;
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

    /** @var list<string> */
    private array $invalidWorkerSettings = [];

    public function __construct(
        private readonly ConfigStore $store,
        private readonly JsonShapeValidator $jsonShapes,
        private readonly ResponseCacheEpoch $responseCacheEpoch,
    ) {}

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

        $this->invalidWorkerSettings = [];
        $this->captureDefaults();
        $applied = [];
        foreach ($this->definitions() as $key => $definition) {
            $isSaved = array_key_exists($key, $saved);
            [$fallback, $source] = $isSaved ? [null, 'database'] : $this->fallbackValue($key, $definition);
            if (! $isSaved && $source !== 'environment') {
                continue;
            }
            if ($isSaved && $this->isWorkerSetting($key, $definition)) {
                try {
                    $value = $this->storedWorkerValue($key, $definition, $saved[$key], $saved);
                } catch (Throwable $exception) {
                    Log::warning('Invalid stored worker setting ignored; keeping the current layout.', [
                        'key' => $key,
                        'reason' => $exception->getMessage(),
                    ]);
                    $this->invalidWorkerSettings[] = $key;
                    continue;
                }
            } else {
                try {
                    $value = $isSaved
                        ? $this->effectiveValue($key, $definition, $this->storedValue($key, $definition, $saved[$key]))
                        : $fallback;
                } catch (Throwable $exception) {
                    Log::warning('Invalid stored setting ignored; using the code default.', [
                        'key' => $key,
                        'reason' => $exception->getMessage(),
                    ]);
                    $value = $this->default($key);
                }
            }
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
            $warning = null;
            if ($inDatabase && $this->isWorkerSetting($key, $definition)) {
                try {
                    $current = $this->storedWorkerValue($key, $definition, $saved[$key], $saved);
                } catch (Throwable $exception) {
                    Log::warning('Invalid stored worker setting shown with a warning.', [
                        'key' => $key,
                        'reason' => $exception->getMessage(),
                    ]);
                    $warning = 'Giá trị đã lưu không hợp lệ; worker đang giữ cấu hình hiện tại. Hãy lưu lại giá trị hợp lệ.';
                    try {
                        $current = $this->effectiveValue($key, $definition, $this->fromStorage($definition, $saved[$key]));
                    } catch (Throwable) {
                        $current = $saved[$key];
                    }
                }
            } else {
                try {
                    $current = $this->effectiveValue(
                        $key,
                        $definition,
                        $inDatabase ? $this->storedValue($key, $definition, $saved[$key]) : $fallback,
                    );
                } catch (Throwable $exception) {
                    Log::warning('Invalid stored setting shown with its default.', [
                        'key' => $key,
                        'reason' => $exception->getMessage(),
                    ]);
                    $warning = 'Giá trị đã lưu không hợp lệ; đang dùng giá trị mặc định.';
                    $current = $this->default($key);
                    $inDatabase = false;
                    $source = 'default';
                }
            }
            $warning ??= $this->urlWarning($key, $definition, $current);
            if ($warning !== null && ! $this->isWorkerSetting($key, $definition)) {
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
                'item_options' => $definition['item_options'] ?? null,
                'format' => $definition['format'] ?? null,
                'restart' => $definition['restart'] ?? null,
                'apply_after_seconds' => (int) ($definition['apply_after_seconds'] ?? 30),
                'shape' => $definition['shape'] ?? null,
                'max_length' => (int) ($definition['max_length'] ?? config('javmeta_settings.validation.max_text_length', 500)),
                'multiline' => (bool) ($definition['multiline'] ?? false),
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
        // The epoch rotates even when the value is unchanged: a retry after a failed rotation must still invalidate.
        if (in_array($key, ['normalization.location_aliases', 'normalization.cups', 'normalization.blood_types'], true)) {
            $this->responseCacheEpoch->bump();
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
        return $this->importPlan()['rows'];
    }

    /** @return list<array{key: string, environment: string, reason: string}> */
    public function importSkippedPreview(): array
    {
        return $this->importPlan()['skipped'];
    }

    /** @return list<string> */
    public function invalidWorkerSettings(): array
    {
        return $this->invalidWorkerSettings;
    }

    /** Copy the current effective settings into the database once; returns the keys written. */
    public function importDefaults(): array
    {
        $entries = [];
        foreach ($this->importPlan()['rows'] as $row) {
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

    /** @return list<array{setting: string, environment: string}> */
    public function redundantEnvironmentKeys(): array
    {
        $saved = $this->store->group(self::GROUP);
        $rows = [];
        foreach ($this->definitions() as $key => $definition) {
            if (! array_key_exists($key, $saved)) {
                continue;
            }
            foreach ((array) ($definition['legacy_env'] ?? []) as $environment) {
                $rows[] = ['setting' => $key, 'environment' => (string) $environment];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function missingWorkerBootstrapSettings(): array
    {
        $saved = $this->store->group(self::GROUP);
        $missing = [];
        foreach ($this->definitions() as $key => $definition) {
            $workerSetting = in_array(($definition['group'] ?? null), ['worker', 'queue', 'timeouts'], true);
            if (! $workerSetting || ($definition['bootstrap_only'] ?? false) !== true || ($definition['legacy_env'] ?? []) === []) {
                continue;
            }
            if (! array_key_exists($key, $saved)) {
                $missing[] = $key;

                continue;
            }
            try {
                $this->storedWorkerValue($key, $definition, $saved[$key], $saved);
            } catch (Throwable) {
                $missing[] = $key;
            }
        }

        return $missing;
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
        if ($inDatabase && $this->isWorkerSetting($key, $definition)) {
            try {
                return $this->storedWorkerValue($key, $definition, $saved[$key], $saved);
            } catch (Throwable $exception) {
                Log::warning('Invalid stored worker setting ignored; using the code default.', [
                    'key' => $key,
                    'reason' => $exception->getMessage(),
                ]);

                return $this->default($key);
            }
        }
        try {
            $value = $this->effectiveValue(
                $key,
                $definition,
                $inDatabase ? $this->storedValue($key, $definition, $saved[$key]) : $fallback,
            );
        } catch (Throwable $exception) {
            Log::warning('Invalid stored setting ignored; using the code default.', [
                'key' => $key,
                'reason' => $exception->getMessage(),
            ]);

            return $this->default($key);
        }

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
        if (($definition['bootstrap_only'] ?? false) === true) {
            return [$this->default($key), 'default'];
        }
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
    private function isWorkerSetting(string $key, array $definition): bool
    {
        return ($definition['group'] ?? null) === 'worker' || $key === 'queue.worker_timeout';
    }

    /** @param array<string, mixed> $saved */
    private function storedWorkerValue(string $key, array $definition, mixed $stored, array $saved): mixed
    {
        $value = $this->effectiveValue($key, $definition, $this->fromStorage($definition, $stored));
        $value = $this->validate($key, $definition, $value);
        if (in_array($key, ['queue.worker_timeout', 'worker.stop_grace_seconds'], true)) {
            $this->assertStoredWorkerGrace($saved);
        }

        return $value;
    }

    /** @param array<string, mixed> $saved */
    private function assertStoredWorkerGrace(array $saved): void
    {
        $timeout = $this->storedOrDefaultWorkerValue('queue.worker_timeout', $saved);
        $grace = $this->storedOrDefaultWorkerValue('worker.stop_grace_seconds', $saved);
        if ((int) $grace < (int) $timeout) {
            throw new InvalidArgumentException(__('settings.validation.worker_stop_grace'));
        }
    }

    /** @param array<string, mixed> $saved */
    private function storedOrDefaultWorkerValue(string $key, array $saved): mixed
    {
        $definition = $this->definitions()[$key];
        if (! array_key_exists($key, $saved)) {
            return $this->default($key);
        }

        $value = $this->effectiveValue($key, $definition, $this->fromStorage($definition, $saved[$key]));

        return $this->validate($key, $definition, $value);
    }

    /** @return array{rows: list<array{key: string, value: mixed, display: mixed, source: string}>, skipped: list<array{key: string, environment: string, reason: string}>} */
    private function importPlan(): array
    {
        $this->captureDefaults();
        $saved = $this->store->group(self::GROUP);
        $rows = [];
        $skipped = [];
        foreach ($this->definitions() as $key => $definition) {
            if (array_key_exists($key, $saved)) {
                continue;
            }
            $legacy = $this->legacyEntry($definition);
            $sourceValue = $legacy['value'] ?? $this->default($key);
            if ($this->isDeploySpecificPath($sourceValue)) {
                $skipped[] = [
                    'key' => $key,
                    'environment' => $legacy['name'] ?? 'code default',
                    'reason' => 'deploy-specific path',
                ];
                continue;
            }
            $source = $legacy === null ? 'default' : 'environment';
            $value = $sourceValue;
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

        $imported = array_column($rows, 'value', 'key');
        if (array_key_exists('queue.worker_timeout', $imported) || array_key_exists('worker.stop_grace_seconds', $imported)) {
            $this->assertTimeoutRelationshipsForValues($imported, $saved);
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** @return array{name: string, value: mixed}|null */
    private function legacyEntry(array $definition): ?array
    {
        foreach ((array) ($definition['legacy_env'] ?? []) as $name) {
            $value = Env::get((string) $name);
            if ($value !== false && $value !== null && $value !== '') {
                return ['name' => (string) $name, 'value' => $value];
            }
        }

        return null;
    }

    private function isDeploySpecificPath(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $part) {
                if ($this->isDeploySpecificPath($part)) {
                    return true;
                }
            }

            return false;
        }
        if (! is_string($value)) {
            return false;
        }

        return preg_match('~(?:^|[\\\\/])(?:releases?[\\\\/][^\\\\/\\s]+|current|release-[^\\\\/\\s]+|builds?[\\\\/][^\\\\/\\s]+)(?:[\\\\/]|$)~i', $value) === 1;
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
            $items = PerformerNormalizationSettingsValidator::normalizeAllowList($key, $items, $fail);
            if ($key === 'report.channels' && $items === []) {
                $fail('select at least one delivery channel');
            }
            foreach ($items as $item) {
                if (isset($definition['item_options']) && ! in_array($item, $definition['item_options'], true)) {
                    $fail('entries must be one of ' . implode(', ', $definition['item_options']));
                }
            }

            return $items;
        }
        if ($type === 'int_list') {
            $items = $this->validateIntList($value, $fail);
            if (isset($definition['max_items']) && count($items) > (int) $definition['max_items']) {
                $fail('has too many entries');
            }
            foreach ($items as $item) {
                if (isset($definition['min_item']) && $item < (int) $definition['min_item']) {
                    $fail('entries must be at least ' . $definition['min_item']);
                }
                if (isset($definition['max_item']) && $item > (int) $definition['max_item']) {
                    $fail('entries must be at most ' . $definition['max_item']);
                }
            }

            return $items;
        }
        if ($type === 'json') {
            return $this->validateJson($key, $definition, $value, $fail);
        }
        // string and secret — Laravel's ConvertEmptyStringsToNull turns cleared inputs into null.
        if ($value === null) {
            $value = '';
        }
        if (! is_string($value) && ! is_numeric($value)) {
            $fail('must be text');
        }
        $text = trim((string) $value);
        $maxLength = (int) ($definition['max_length'] ?? config('javmeta_settings.validation.max_text_length', 500));
        if (mb_strlen($text) > $maxLength) {
            $fail('is too long');
        }
        if (($definition['format'] ?? null) === 'timezone') {
            try {
                new DateTimeZone($text);
            } catch (\Throwable) {
                $fail('must be a valid IANA timezone');
            }
        }
        if (($definition['format'] ?? null) === 'time' && $text !== '' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $text) !== 1) {
            $fail('must use HH:MM');
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
        $retryQueues = array_values((array) config('javmeta_queues.retry_names', []));
        foreach ($crawlQueues as $index => $queue) {
            $browserQueue = $browserQueues[$index] ?? null;
            if (in_array($queue, $items, true) && is_string($browserQueue) && ! in_array($browserQueue, $items, true)) {
                $items[] = $browserQueue;
            }
            if ((in_array($queue, $items, true) || (is_string($browserQueue) && in_array($browserQueue, $items, true)))
                && isset($retryQueues[$index]) && ! in_array($retryQueues[$index], $items, true)) {
                $items[] = $retryQueues[$index];
            }
        }

        $downloadSearchQueue = (string) config('javmeta_queues.download_search_queue', 'download_search');
        if (! in_array($downloadSearchQueue, $items, true)) {
            $items[] = $downloadSearchQueue;
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
        $maxLength = isset($definition['max_length']) ? (int) $definition['max_length'] : null;
        if (is_string($value)) {
            if ($maxLength !== null && mb_strlen($value) > $maxLength) {
                $fail('is too long');
            }
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
        if ($maxLength !== null && mb_strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) > $maxLength) {
            $fail('is too long');
        }
        if (count($value) > (int) ($definition['max_entries'] ?? config('javmeta_settings.validation.max_json_entries', 50))) {
            $fail('has too many entries');
        }
        $this->jsonShapes->validate((string) ($definition['shape'] ?? ''), $value, $fail);

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
    private function storedValue(string $key, array $definition, mixed $stored): mixed
    {
        $value = $this->fromStorage($definition, $stored);
        if (in_array($definition['shape'] ?? null, ['source_gap_override_map', 'retry_fetch_profile'], true)) {
            return $this->validate($key, $definition, $value);
        }

        return $value;
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

    private function legacyValue(array $definition): mixed
    {
        return $this->legacyEntry($definition)['value'] ?? null;
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
        $this->assertTimeoutRelationshipsForValues([$key => $value]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed>|null $saved
     */
    private function assertTimeoutRelationshipsForValues(array $overrides, ?array $saved = null): void
    {
        $candidate = function (string $setting) use ($overrides, $saved): mixed {
            if (array_key_exists($setting, $overrides)) {
                return $overrides[$setting];
            }
            if ($saved === null) {
                return $this->get($setting);
            }
            if (! array_key_exists($setting, $saved)) {
                return $this->default($setting);
            }
            $definition = $this->definitions()[$setting];

            return $this->validate($setting, $definition, $this->effectiveValue($setting, $definition, $this->fromStorage($definition, $saved[$setting])));
        };
        (new TimeoutRelationshipValidator())->validate($candidate);
        $validateSuccessRatePair = array_key_exists('alerts.success_rate_min_percent', $overrides)
            || array_key_exists('alerts.success_rate_critical_percent', $overrides);
        (new AlertThresholdOrderValidator())->validate($candidate, $validateSuccessRatePair);
    }
}
