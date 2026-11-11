<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Movie;
use App\Repositories\MovieRepository;
use App\Services\Performance\PerformanceCache;
use App\Services\Api\ResponseCacheEpoch;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class PerformanceCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_response_cache.store' => 'array']);
    }

    public function test_warmer_refreshes_existing_values_and_readers_share_the_cache_keys(): void
    {
        Movie::factory()->create();
        $this->artisan('perf:warm')->assertSuccessful();
        $cache = app(PerformanceCache::class);
        $summary = $cache->summary();
        $this->assertSame(1, $summary['counts']['movies']);
        $this->assertArrayNotHasKey('sources', $summary);
        $this->assertSame(1, $cache->facets()['total']);
        Movie::factory()->create();
        $this->assertSame(1, $cache->facets()['total']);
        $this->artisan('perf:warm')->assertSuccessful();
        $this->assertSame(2, $cache->facets()['total']);
    }

    public function test_disabled_warming_does_not_build_any_cache(): void
    {
        $store = $this->useRecordingPerformanceStore();
        config(['javmeta_perf.warm_enabled' => false]);
        $this->artisan('perf:warm')->assertSuccessful();
        $this->assertSame([], $store->writes);
    }

    public function test_status_summary_is_rebuilt_without_a_second_inner_cache(): void
    {
        Movie::factory()->create();
        $cache = app(PerformanceCache::class);
        $this->assertSame(1, $cache->summary()['counts']['movies']);

        Movie::factory()->create();

        $this->assertSame(2, $cache->summary()['counts']['movies']);
    }

    public function test_facets_cache_keeps_filter_results_isolated(): void
    {
        $cache = app(PerformanceCache::class);
        Movie::factory()->create(['maker' => 'Sample Maker 03']);
        Movie::factory()->create(['maker' => 'Sample Maker 04']);
        Cache::store('array')->flush();

        $generic = $cache->facets([]);
        $this->assertSame($generic, $cache->facets([]));
        $this->assertSame(2, $generic['total']);

        $narrow = $cache->facets(['maker' => 'Sample Maker 03']);
        $this->assertSame(1, $narrow['total']);
        $this->assertSame($generic, $cache->facets([]));
    }

    public function test_status_only_warming_does_not_create_a_status_cache(): void
    {
        $store = $this->useRecordingPerformanceStore();
        $this->artisan('perf:warm --status-only')->assertSuccessful();
        $this->assertSame([], $store->writes);
    }

    public function test_many_filter_values_and_epoch_changes_stay_within_the_fixed_cache_budget(): void
    {
        $store = $this->useRecordingPerformanceStore();
        $cache = app(PerformanceCache::class);

        for ($i = 0; $i < 200; $i++) {
            $cache->facets(['maker' => 'query-' . $i]);
        }
        app(ResponseCacheEpoch::class)->bump();
        for ($i = 200; $i < 400; $i++) {
            $cache->facets(['maker' => 'query-' . $i]);
        }

        $slots = (int) config('javmeta_response_cache.slots_per_class');
        $maxBytes = (int) config('javmeta_response_cache.max_entry_bytes');
        $this->assertLessThanOrEqual($slots, count($store->writes));
        $this->assertLessThanOrEqual(
            $slots * $maxBytes,
            array_sum(array_map(static fn(array $entry): int => strlen(serialize($entry)), array_values($store->writes))),
        );
    }

    public function test_oversized_facet_values_are_returned_but_not_cached(): void
    {
        $store = $this->useRecordingPerformanceStore();
        Movie::factory()->create(['maker' => str_repeat('x', (int) config('javmeta_response_cache.max_entry_bytes') + 1)]);
        $cache = app(PerformanceCache::class);

        $first = $cache->facets();
        $second = $cache->facets();

        $this->assertSame($first, $second);
        $this->assertGreaterThan((int) config('javmeta_response_cache.max_entry_bytes'), strlen(serialize($first)));
        $this->assertSame([], $store->writes);
    }

    public function test_genre_facets_count_a_movie_once_even_for_shared_labels_and_multiple_sources(): void
    {
        $a = Movie::factory()->create(['maker' => 'A']);
        $b = Movie::factory()->create(['maker' => 'B']);
        $one = Genre::factory()->create(['label_raw' => 'Drama']);
        $two = Genre::factory()->create(['label_raw' => 'Drama']);
        $a->genres()->attach([$one->id, $two->id], ['source_slug' => 'one', 'crawled_at' => now()]);
        $a->genres()->attach($one->id, ['source_slug' => 'two', 'crawled_at' => now()]);
        $b->genres()->attach($one->id, ['source_slug' => 'one', 'crawled_at' => now()]);
        $repo = app(MovieRepository::class);
        $this->assertSame([['name' => 'Drama', 'count' => 2]], $repo->facets([])['genres']);
        $this->assertSame([['name' => 'Drama', 'count' => 1]], $repo->facets(['maker' => 'A'])['genres']);
        $this->assertSame([], $repo->facets(['maker' => 'absent'])['genres']);
    }

    private function useRecordingPerformanceStore(): object
    {
        $store = new class extends ArrayStore {
            /** @var array<string, mixed> */
            public array $writes = [];

            public function put($key, $value, $seconds): bool
            {
                $this->writes[(string) $key] = $value;

                return parent::put($key, $value, $seconds);
            }
        };

        Cache::extend('performance-recording', static fn($app, array $config) => Cache::repository($store, $config));
        config([
            'cache.stores.performance-recording' => ['driver' => 'performance-recording'],
            'javmeta_response_cache.store' => 'performance-recording',
        ]);

        return $store;
    }
}
