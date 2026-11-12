<?php

declare(strict_types=1);

namespace App\Observability;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class ResponseCacheMetrics
{
    /** @var list<string> */
    private const CLASSES = ['lists', 'facets', 'catalog', 'suggestions', 'status', 'movie_detail'];

    /** @var array<string, string> */
    private const NAMES = [
        'hits' => 'api_response_cache_hits_total',
        'misses' => 'api_response_cache_misses_total',
        'bypass' => 'api_response_cache_bypass_total',
        'stale_served' => 'api_response_cache_stale_served_total',
        'bytes' => 'api_response_cache_bytes_total',
    ];

    public function record(string $class, string $metric, int $amount = 1): void
    {
        if (! in_array($class, self::CLASSES, true) || ! array_key_exists($metric, self::NAMES) || $amount < 1) {
            return;
        }

        try {
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
            $key = $this->key($class, $metric);
            $store->add($key, 0, (int) config('javmeta_response_cache.metrics_ttl_seconds', 7_776_000));
            $store->increment($key, $amount);
        } catch (Throwable) {
            // Metrics must never change the API response or the crawl path.
        }
    }

    /** @return list<array{name: string, value: int, labels: array<string, string>}> */
    public function points(): array
    {
        $points = [];
        try {
            $store = Cache::store((string) config('javmeta_response_cache.store', 'redis'));
            foreach (self::CLASSES as $class) {
                foreach (self::NAMES as $metric => $name) {
                    $points[] = [
                        'name' => MetricStreams::NAMES[$name],
                        'value' => max(0, (int) $store->get($this->key($class, $metric), 0)),
                        'labels' => ['endpoint_class' => $class],
                    ];
                }
            }
        } catch (Throwable) {
            return [];
        }

        return $points;
    }

    private function key(string $class, string $metric): string
    {
        return 'javmeta:api-response:metrics:v1:' . $class . ':' . $metric;
    }
}
