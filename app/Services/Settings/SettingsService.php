<?php

declare(strict_types=1);

namespace App\Services\Settings;

use InvalidArgumentException;
use RuntimeException;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Support\ConfigType;
use Throwable;

/**
 * The settings the owner edits in the UI. Values live in the laravel-config store (group "settings"); `apply()` pushes
 * them into the Laravel config so existing code keeps reading config(). A key that was never saved keeps the config
 * default (which may still come from env), so nothing breaks before `settings:import-env` has run.
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
    public function apply(): void
    {
        try {
            $saved = $this->store->group(self::GROUP);
        } catch (Throwable) {
            return;
        }

        $this->captureDefaults();
        foreach ($this->definitions() as $key => $definition) {
            if (! array_key_exists($key, $saved)) {
                continue;
            }
            $value = $this->fromStorage($definition, $saved[$key]);
            foreach ((array) $definition['config'] as $configKey) {
                config([$configKey => $value]);
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
            $current = $inDatabase ? $this->fromStorage($definition, $saved[$key]) : $this->default($key);
            $rows[] = [
                'key' => $key,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
                'restart' => $definition['restart'] ?? null,
                'source' => $inDatabase ? 'database' : 'default',
                'value' => $isSecret ? null : $current,
                'is_set' => $isSecret ? (is_string($current) && $current !== '') : null,
            ];
        }

        return $rows;
    }

    /**
     * Validate and save one setting. An empty secret clears it.
     *
     * @return array{key: string, old: mixed, new: mixed, secret: bool}
     */
    public function set(string $key, mixed $value): array
    {
        $definition = $this->definitions()[$key] ?? throw new InvalidArgumentException("Unknown setting {$key}.");
        $clean = $this->validate($key, $definition, $value);
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

        return ['key' => $key, 'old' => $isSecret ? null : $old, 'new' => $isSecret ? null : $clean, 'secret' => $isSecret];
    }

    /** Copy the current effective value of every setting that is not in the database yet; returns the keys written. */
    public function importDefaults(): array
    {
        $this->captureDefaults();
        $saved = $this->store->group(self::GROUP);
        $entries = [];
        $written = [];
        foreach ($this->definitions() as $key => $definition) {
            if (array_key_exists($key, $saved)) {
                continue;
            }
            $value = $this->default($key);
            if ($value === null || $value === '') {
                continue;
            }
            $entries[self::GROUP . '.' . $key] = [
                'value' => $this->toStorage($definition, $value),
                'type' => $this->storageType($definition),
            ];
            $written[] = $key;
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

        return $written;
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

        return array_key_exists($key, $saved) ? $this->fromStorage($definition, $saved[$key]) : $this->default($key);
    }

    private function default(string $key): mixed
    {
        $this->captureDefaults();

        return self::$defaults[$key] ?? null;
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

            return $number;
        }
        if ($type === 'string_list') {
            $parts = is_array($value) ? $value : preg_split('/[\r\n,]+/', (string) $value);
            $items = array_values(array_unique(array_filter(array_map(static fn($p): string => trim((string) $p), $parts ?: []), static fn(string $p): bool => $p !== '')));
            foreach ($items as $item) {
                if (mb_strlen($item) > 500) {
                    $fail('an entry is too long');
                }
            }

            return $items;
        }
        if ($type === 'int_list') {
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

            return $numbers === [] ? $fail('needs at least one number') : $numbers;
        }
        // string and secret
        if (! is_string($value) && ! is_numeric($value)) {
            $fail('must be text');
        }
        $text = trim((string) $value);
        if (mb_strlen($text) > 500) {
            $fail('is too long');
        }
        if (isset($definition['options']) && ! in_array($text, $definition['options'], true)) {
            $fail('must be one of ' . implode(', ', $definition['options']));
        }

        return $text;
    }

    /** @param array<string, mixed> $definition */
    private function toStorage(array $definition, mixed $value): mixed
    {
        return match ($definition['type']) {
            'int_list' => implode(',', (array) $value),
            'string_list' => implode("\n", (array) $value),
            default => $value,
        };
    }

    /** @param array<string, mixed> $definition */
    private function fromStorage(array $definition, mixed $stored): mixed
    {
        return match ($definition['type']) {
            'int' => (int) $stored,
            'float' => (float) $stored,
            'bool' => filter_var($stored, FILTER_VALIDATE_BOOLEAN),
            'int_list' => array_values(array_filter(array_map('intval', explode(',', (string) $stored)), static fn(int $n): bool => $n > 0)),
            'string_list' => array_values(array_filter(array_map('trim', preg_split('/[\r\n]+/', (string) $stored) ?: []), static fn(string $p): bool => $p !== '')),
            default => (string) $stored,
        };
    }

    /** @param array<string, mixed> $definition */
    private function storageType(array $definition): string
    {
        return match ($definition['type']) {
            'int' => ConfigType::Int->value,
            'float' => ConfigType::Float->value,
            'bool' => ConfigType::Bool->value,
            'secret' => ConfigType::Encrypted->value,
            default => ConfigType::String->value,
        };
    }
}
