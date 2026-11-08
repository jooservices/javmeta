<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Worker liveness tracking.
 *
 * Every crawl job writes a heartbeat for its own instance (hostname:pid) plus
 * the legacy global key so existing consumers keep working. `instances()` lists
 * every known worker instance with its last beat and staleness, which lets
 * monitoring see the health of each worker process / container.
 */
final class WorkerHeartbeat
{
    public const CACHE_KEY = 'javmeta:worker:heartbeat';
    public const INDEX_KEY = 'javmeta:worker:instances';
    /** An instance without a beat for this long is forgotten by the index. */
    public const INDEX_RETENTION_SECONDS = 3600;
    /** Hard cap on the number of instances kept in the index. */
    public const INDEX_MAX_ENTRIES = 200;
    /** Minimum gap between two index writes for the same instance. */
    public const INDEX_WRITE_INTERVAL_SECONDS = 30;

    public function beat(?string $instance = null): void
    {
        $instance = $instance ?? $this->currentInstance();
        $timestamp = now()->getTimestamp();

        Cache::put(self::CACHE_KEY, $timestamp, now()->addSeconds(self::INDEX_RETENTION_SECONDS));
        Cache::put($this->instanceKey($instance), $timestamp, now()->addSeconds(self::INDEX_RETENTION_SECONDS));
        $this->touchIndex($instance, $timestamp);
    }

    public function currentInstance(): string
    {
        return gethostname() . ':' . getmypid();
    }

    public function lastBeatAt(?string $instance = null): ?Carbon
    {
        $value = Cache::get($instance === null ? self::CACHE_KEY : $this->instanceKey($instance));

        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : null;
    }

    public function isStale(?string $instance = null, ?int $staleSeconds = null): bool
    {
        $threshold = $staleSeconds ?? (int) config('javmeta_alerts.watchdog.heartbeat_stale_seconds', 600);
        $last = $this->lastBeatAt($instance);

        if ($last === null) {
            return true;
        }

        return $last->diffInSeconds(now()) > $threshold;
    }

    /**
     * @return list<array{instance: string, last_heartbeat_at: string|null, stale: bool}>
     */
    public function instances(?int $staleSeconds = null): array
    {
        $threshold = $staleSeconds ?? (int) config('javmeta_alerts.watchdog.heartbeat_stale_seconds', 600);
        $index = Cache::get(self::INDEX_KEY, []);
        if (! is_array($index)) {
            return [];
        }

        // Filter the raw integers first: a legacy index can hold tens of thousands of entries.
        $rows = [];
        foreach ($this->retained($index, now()->getTimestamp()) as $instance => $timestamp) {
            $last = Carbon::createFromTimestamp($timestamp);
            $rows[] = [
                'instance' => $instance,
                'last_heartbeat_at' => $last->toIso8601String(),
                'stale' => $last->diffInSeconds(now()) > $threshold,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => strcmp($a['instance'], $b['instance']));

        return $rows;
    }

    private function instanceKey(string $instance): string
    {
        return self::CACHE_KEY . ':' . $instance;
    }

    private function touchIndex(string $instance, int $timestamp): void
    {
        $index = Cache::get(self::INDEX_KEY, []);
        if (! is_array($index)) {
            $index = [];
        }

        $previous = $index[$instance] ?? null;
        if (is_numeric($previous) && $timestamp - (int) $previous <= self::INDEX_WRITE_INTERVAL_SECONDS) {
            return;
        }

        $index[$instance] = $timestamp;
        $index = $this->retained($index, $timestamp);
        Cache::put(self::INDEX_KEY, $index, now()->addSeconds(self::INDEX_RETENTION_SECONDS));
    }

    /**
     * Drops malformed and expired entries, then keeps the newest INDEX_MAX_ENTRIES.
     *
     * @param array<array-key, mixed> $index
     * @return array<string, int> instance => last beat timestamp, newest first
     */
    private function retained(array $index, int $now): array
    {
        $cutoff = $now - self::INDEX_RETENTION_SECONDS;
        $fresh = [];
        foreach ($index as $instance => $timestamp) {
            if (is_string($instance) && is_numeric($timestamp) && (int) $timestamp >= $cutoff) {
                $fresh[$instance] = (int) $timestamp;
            }
        }

        arsort($fresh);

        return array_slice($fresh, 0, self::INDEX_MAX_ENTRIES, true);
    }
}
