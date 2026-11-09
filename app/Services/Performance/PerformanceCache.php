<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Repositories\MovieRepository;
use App\Services\Api\ResponseCacheEpoch;
use App\Services\Crawl\CrawlStatusService;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PerformanceCache
{
    public function __construct(
        private readonly CrawlStatusService $status,
        private readonly MovieRepository $movies,
        private readonly ResponseCacheEpoch $epoch,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $data = $this->status->status();
        unset($data['sources']);

        return $data;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters = [], bool $refresh = false): array
    {
        if (! (bool) config('javmeta_response_cache.enabled', true)) {
            return $this->movies->facets($filters);
        }

        try {
            $fingerprint = hash('sha256', json_encode([
                'version' => 3,
                'epoch' => $this->epoch->current(),
                'filters' => $filters,
            ], JSON_THROW_ON_ERROR));
            $slots = max(1, (int) config('javmeta_response_cache.slots_per_class', 64));
            $slot = (int) hexdec(substr($fingerprint, 0, 8)) % $slots;
            $key = 'javmeta:performance:v3:facets:' . $slot;
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));

            if (! $refresh) {
                $cached = $store->get($key);
                if (is_array($cached) && isset($cached['fingerprint'], $cached['data'])
                    && is_string($cached['fingerprint']) && hash_equals($fingerprint, $cached['fingerprint'])
                    && is_array($cached['data'])) {
                    return $cached['data'];
                }
            }
        } catch (Throwable) {
            return $this->movies->facets($filters);
        }

        $data = $this->movies->facets($filters);
        try {
            $entry = ['fingerprint' => $fingerprint, 'data' => $data];
            if (strlen(serialize($entry)) <= max(1, (int) config('javmeta_response_cache.max_entry_bytes', 131_072))) {
                $store->put($key, $entry, 60);
            }
        } catch (Throwable) {
            // Cache and encoding failures must not hide the live result.
        }

        return $data;
    }
}
