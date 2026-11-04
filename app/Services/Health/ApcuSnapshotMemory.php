<?php

declare(strict_types=1);

namespace App\Services\Health;

/** Holds the snapshot in APCu when the extension is loaded and enabled; otherwise it holds nothing. */
final class ApcuSnapshotMemory implements SnapshotMemory
{
    private const KEY = 'javmeta.startup-health.snapshot';

    public function fetch(): ?array
    {
        if (! $this->available()) {
            return null;
        }
        $found = false;
        $record = apcu_fetch(self::KEY, $found);

        return $found && is_array($record) ? $record : null;
    }

    public function store(array $record, int $ttlSeconds): void
    {
        if ($this->available()) {
            @apcu_store(self::KEY, $record, max(1, $ttlSeconds));
        }
    }

    public function forget(): void
    {
        if ($this->available()) {
            apcu_delete(self::KEY);
        }
    }

    private function available(): bool
    {
        return function_exists('apcu_fetch') && apcu_enabled();
    }
}
