<?php

declare(strict_types=1);

namespace App\Services\Health;

/** A fast in-process cache in front of the snapshot file. */
interface SnapshotMemory
{
    /** @return array<string, mixed>|null the stored record, or null when nothing is held */
    public function fetch(): ?array;

    /** @param array<string, mixed> $record */
    public function store(array $record, int $ttlSeconds): void;

    public function forget(): void;
}
