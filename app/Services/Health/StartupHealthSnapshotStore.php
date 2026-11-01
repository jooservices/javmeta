<?php

declare(strict_types=1);

namespace App\Services\Health;

use Closure;
use Throwable;

final class StartupHealthSnapshotStore
{
    private const APCU_KEY = 'javmeta.startup-health.snapshot';

    private readonly string $path;

    private readonly Closure $clock;

    public function __construct(?string $path = null, ?Closure $clock = null)
    {
        $this->path = $path ?? storage_path('framework/cache/startup-health.json');
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        if (function_exists('apcu_fetch') && apcu_enabled()) {
            $found = false;
            $record = apcu_fetch(self::APCU_KEY, $found);
            if ($found && is_array($record) && (int) ($record['expires_at'] ?? 0) > ($this->clock)()) {
                return is_array($record['report'] ?? null) ? $record['report'] : null;
            }
            apcu_delete(self::APCU_KEY);
        }

        try {
            $record = json_decode((string) @file_get_contents($this->path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($record) || (int) ($record['expires_at'] ?? 0) <= ($this->clock)()) {
                return null;
            }

            return is_array($record['report'] ?? null) ? $record['report'] : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $report */
    public function put(array $report, int $ttlSeconds): void
    {
        $expiresAt = ($this->clock)() + max(1, $ttlSeconds);
        $record = ['expires_at' => $expiresAt, 'report' => $report];

        if (function_exists('apcu_store') && apcu_enabled()) {
            @apcu_store(self::APCU_KEY, $record, max(1, $ttlSeconds));
        }

        $temporary = $this->path . '.' . getmypid() . '.tmp';
        try {
            if (! is_dir(dirname($this->path)) && ! mkdir(dirname($this->path), 0770, true) && ! is_dir(dirname($this->path))) {
                return;
            }
            $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($temporary, $json, LOCK_EX) === false) {
                return;
            }
            @chmod($temporary, 0660);
            if (! rename($temporary, $this->path)) {
                @unlink($temporary);
            }
        } catch (Throwable) {
            @unlink($temporary);
        }
    }
}
