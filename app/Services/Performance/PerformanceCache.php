<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Repositories\MovieRepository;
use App\Services\Crawl\CrawlStatusService;
use App\Services\Api\ResponseCacheEpoch;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PerformanceCache
{
    public function __construct(private readonly CrawlStatusService $status, private readonly MovieRepository $movies, private readonly ResponseCacheEpoch $epoch) {}

    /** @return array<string, mixed> */
    public function summary(bool $refresh = false): array
    {
        $build = function (): array {
            $data = $this->status->status();
            unset($data['sources']);

            return $data;
        };
        return $this->cacheData('status:summary', 15, $refresh, $build);
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters = [], bool $refresh = false): array
    {
        $encoded = json_encode($filters, JSON_THROW_ON_ERROR);

        return $this->cacheData('facets:' . hash('sha256', $encoded), 60, $refresh, fn(): array => $this->movies->facets($filters));
    }

    /** @param callable(): array<string, mixed> $build
     * @return array<string, mixed>
     */
    private function cacheData(string $suffix, int $ttl, bool $refresh, callable $build): array
    {
        if (! (bool) config('javmeta_response_cache.enabled', true)) {
            return $build();
        }

        $store = null;
        $key = null;
        try {
            $epoch = $this->epoch->current();
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
            $key = 'javmeta:performance:v2:' . $epoch . ':' . $suffix;
            if (! $refresh) {
                $cached = $store->get($key);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        } catch (Throwable) {
            return $build();
        }

        $data = $build();
        try {
            $store->put($key, $data, $ttl);
        } catch (Throwable) {
            // The cache is optional; the computed result remains valid.
        }

        return $data;
    }
}
