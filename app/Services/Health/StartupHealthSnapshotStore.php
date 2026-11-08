<?php

declare(strict_types=1);

namespace App\Services\Health;

use Closure;
use Throwable;

final class StartupHealthSnapshotStore
{
    private readonly string $path;

    private readonly Closure $clock;

    private readonly SnapshotMemory $memory;

    public function __construct(?string $path = null, ?Closure $clock = null, ?SnapshotMemory $memory = null)
    {
        $this->path = $path ?? storage_path('framework/cache/startup-health.json');
        $this->clock = $clock ?? static fn(): int => time();
        $this->memory = $memory ?? new ApcuSnapshotMemory();
    }

    /** @return array<string, mixed>|null */
    public function get(): ?array
    {
        $remembered = $this->memory->fetch();
        if ($remembered !== null) {
            if ((int) ($remembered['expires_at'] ?? 0) > ($this->clock)()) {
                return is_array($remembered['report'] ?? null) ? $remembered['report'] : null;
            }
            $this->memory->forget();
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
        $ttlSeconds = max(1, $ttlSeconds);
        $expiresAt = ($this->clock)() + $ttlSeconds;
        $record = ['expires_at' => $expiresAt, 'report' => $report];

        $this->memory->store($record, $ttlSeconds);

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
