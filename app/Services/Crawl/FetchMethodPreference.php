<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class FetchMethodPreference
{
    private const PLAYWRIGHT_METHODS = ['playwright', 'playwright_stealth', 'chrome_stealth'];

    public function beforeFetch(string $sourceSlug, string $host): FetchMethodPreferenceDecision
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            return new FetchMethodPreferenceDecision(false, false);
        }

        $key = $this->stateKey($sourceSlug, $host);

        try {
            $decision = $this->withLock($key, function () use ($key): FetchMethodPreferenceDecision {
                $state = $this->state($key);
                $now = now()->getTimestamp();
                if ($state['preferred_until'] <= $now) {
                    return new FetchMethodPreferenceDecision(false, false);
                }

                $interval = max(1, (int) config('javmeta_queues.fetch_method_probe_interval', 10));
                $requests = $state['requests_since_probe'] + 1;
                $probe = $requests >= $interval;
                $state['requests_since_probe'] = $probe ? 0 : $requests;
                $this->saveState($key, $state);

                return new FetchMethodPreferenceDecision(true, $probe);
            });

            return $decision ?? new FetchMethodPreferenceDecision(false, false);
        } catch (Throwable) {
            return new FetchMethodPreferenceDecision(false, false);
        }
    }

    /** @param list<array<string, mixed>> $attempts */
    public function recordAttempts(string $sourceSlug, string $host, array $attempts): void
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            return;
        }

        $key = $this->stateKey($sourceSlug, $host);

        try {
            $this->withLock($key, function () use ($key, $sourceSlug, $host, $attempts): void {
                $state = $this->state($key);
                $windowSize = max(1, (int) config('javmeta_queues.fetch_method_window_size', 10));
                $now = now()->getTimestamp();
                $playwrightSucceeded = false;
                $changed = false;

                foreach ($attempts as $attempt) {
                    $method = $attempt['method'] ?? null;
                    $outcome = $attempt['outcome'] ?? null;
                    if (! is_string($method) || ! in_array($outcome, ['ok', 'error'], true)) {
                        continue;
                    }

                    $bucket = $this->bucket($method);
                    if ($bucket === null) {
                        continue;
                    }

                    $succeeded = $outcome === 'ok';
                    $state[$bucket][] = ['succeeded' => $succeeded, 'recorded_at' => $now];
                    $state[$bucket] = array_slice($state[$bucket], -$windowSize);
                    $playwrightSucceeded = $playwrightSucceeded || ($bucket === 'playwright' && $succeeded);
                    $changed = true;
                }

                if (! $changed) {
                    return;
                }

                if ($playwrightSucceeded) {
                    $state['preferred_until'] = 0;
                    $state['requests_since_probe'] = 0;
                } elseif ($state['preferred_until'] <= $now && $this->shouldPreferFlaresolverr($state, $windowSize)) {
                    $state['preferred_until'] = $now + max(1, (int) config('javmeta_queues.fetch_method_preference_ttl_seconds', 21600));
                    $state['requests_since_probe'] = 0;
                }

                $this->rememberHost($sourceSlug, $host);
                $this->saveState($key, $state);
            });
        } catch (Throwable) {
            // A cache outage must leave the existing CrawlerX method order intact.
        }
    }

    public function isFlaresolverrPreferredForSource(string $sourceSlug): bool
    {
        try {
            $hosts = Cache::get($this->hostIndexKey($sourceSlug), []);
            if (! is_array($hosts)) {
                return false;
            }

            $now = now()->getTimestamp();
            foreach ($hosts as $host) {
                if (! is_string($host) || $host === '') {
                    continue;
                }

                $state = Cache::get($this->stateKey($sourceSlug, $host));
                if (is_array($state) && (int) ($state['preferred_until'] ?? 0) > $now) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /** @param array{playwright: list<array{succeeded: bool, recorded_at: int}>, flaresolverr: list<array{succeeded: bool, recorded_at: int}>, preferred_until: int, requests_since_probe: int} $state */
    private function shouldPreferFlaresolverr(array $state, int $windowSize): bool
    {
        if (count($state['playwright']) < $windowSize || count($state['flaresolverr']) === 0) {
            return false;
        }

        $threshold = max(1, min($windowSize, (int) config('javmeta_queues.fetch_method_failure_threshold', 8)));
        $failures = count(array_filter(
            $state['playwright'],
            static fn(array $attempt): bool => ! $attempt['succeeded'],
        ));

        $flaresolverrSucceeded = array_filter(
            $state['flaresolverr'],
            static fn(array $attempt): bool => $attempt['succeeded'],
        ) !== [];

        return $failures >= $threshold && $flaresolverrSucceeded;
    }

    private function bucket(string $method): ?string
    {
        if (in_array($method, self::PLAYWRIGHT_METHODS, true)) {
            return 'playwright';
        }

        return $method === 'flaresolverr' ? 'flaresolverr' : null;
    }

    private function rememberHost(string $sourceSlug, string $host): void
    {
        $key = $this->hostIndexKey($sourceSlug);
        $hosts = Cache::get($key, []);
        if (is_array($hosts) && in_array($host, $hosts, true)) {
            return;
        }

        Cache::lock($key . ':lock', 5)->block(5, function () use ($key, $host): void {
            $hosts = Cache::get($key, []);
            $hosts = is_array($hosts) ? $hosts : [];
            if (! in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
            Cache::put($key, $hosts, max(
                (int) config('javmeta_queues.fetch_method_window_ttl_seconds', 86400),
                (int) config('javmeta_queues.fetch_method_preference_ttl_seconds', 21600),
            ));
        });
    }

    /** @return array{playwright: list<array{succeeded: bool, recorded_at: int}>, flaresolverr: list<array{succeeded: bool, recorded_at: int}>, preferred_until: int, requests_since_probe: int} */
    private function state(string $key): array
    {
        $state = Cache::get($key, []);
        $windowStartedAt = now()->getTimestamp() - max(1, (int) config('javmeta_queues.fetch_method_window_ttl_seconds', 86400));

        return [
            'playwright' => $this->recentAttempts($state['playwright'] ?? null, $windowStartedAt),
            'flaresolverr' => $this->recentAttempts($state['flaresolverr'] ?? null, $windowStartedAt),
            'preferred_until' => is_int($state['preferred_until'] ?? null) ? $state['preferred_until'] : 0,
            'requests_since_probe' => is_int($state['requests_since_probe'] ?? null) ? $state['requests_since_probe'] : 0,
        ];
    }

    /** @param array{playwright: list<array{succeeded: bool, recorded_at: int}>, flaresolverr: list<array{succeeded: bool, recorded_at: int}>, preferred_until: int, requests_since_probe: int} $state */
    private function saveState(string $key, array $state): void
    {
        $ttl = max(
            1,
            (int) config('javmeta_queues.fetch_method_window_ttl_seconds', 86400),
            (int) config('javmeta_queues.fetch_method_preference_ttl_seconds', 21600),
        );
        Cache::put($key, $state, $ttl);
    }

    private function withLock(string $key, Closure $callback): mixed
    {
        return Cache::lock($key . ':lock', 5)->block(1, $callback);
    }

    private function stateKey(string $sourceSlug, string $host): string
    {
        return 'crawlerx:fetch-method:state:' . hash('sha256', $sourceSlug . "\n" . $host);
    }

    private function hostIndexKey(string $sourceSlug): string
    {
        return 'crawlerx:fetch-method:hosts:' . hash('sha256', $sourceSlug);
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    /** @return list<array{succeeded: bool, recorded_at: int}> */
    private function recentAttempts(mixed $attempts, int $windowStartedAt): array
    {
        if (! is_array($attempts)) {
            return [];
        }

        $recent = [];
        foreach ($attempts as $attempt) {
            if (! is_array($attempt)
                || ! is_bool($attempt['succeeded'] ?? null)
                || ! is_int($attempt['recorded_at'] ?? null)
                || $attempt['recorded_at'] < $windowStartedAt
            ) {
                continue;
            }

            $recent[] = ['succeeded' => $attempt['succeeded'], 'recorded_at' => $attempt['recorded_at']];
        }

        return $recent;
    }
}
