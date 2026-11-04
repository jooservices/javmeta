<?php

declare(strict_types=1);

namespace App\Services\Health;

use Closure;

/** Holds the snapshot in APCu when the extension is loaded and enabled; otherwise it holds nothing. */
final class ApcuSnapshotMemory implements SnapshotMemory
{
    private const KEY = 'javmeta.startup-health.snapshot';

    /** @var Closure(): bool */
    private readonly Closure $enabled;

    /** @var Closure(string, bool &): mixed */
    private readonly Closure $fetcher;

    /** @var Closure(string, array<string, mixed>, int): mixed */
    private readonly Closure $storer;

    /** @var Closure(string): mixed */
    private readonly Closure $deleter;

    /**
     * The four closures are the seam to the extension; they default to the real apcu_* functions.
     *
     * @param Closure(): bool|null $enabled
     * @param Closure(string, bool &): mixed|null $fetcher
     * @param Closure(string, array<string, mixed>, int): mixed|null $storer
     * @param Closure(string): mixed|null $deleter
     */
    public function __construct(?Closure $enabled = null, ?Closure $fetcher = null, ?Closure $storer = null, ?Closure $deleter = null)
    {
        $this->enabled = $enabled ?? static fn(): bool => function_exists('apcu_fetch') && apcu_enabled();
        $this->fetcher = $fetcher ?? static fn(string $key, bool &$found): mixed => apcu_fetch($key, $found);
        $this->storer = $storer ?? static fn(string $key, array $record, int $ttl): mixed => @apcu_store($key, $record, $ttl);
        $this->deleter = $deleter ?? static fn(string $key): mixed => apcu_delete($key);
    }

    public function fetch(): ?array
    {
        if (! ($this->enabled)()) {
            return null;
        }
        $found = false;
        $record = ($this->fetcher)(self::KEY, $found);

        return $found && is_array($record) ? $record : null;
    }

    public function store(array $record, int $ttlSeconds): void
    {
        if (($this->enabled)()) {
            ($this->storer)(self::KEY, $record, max(1, $ttlSeconds));
        }
    }

    public function forget(): void
    {
        if (($this->enabled)()) {
            ($this->deleter)(self::KEY);
        }
    }
}
