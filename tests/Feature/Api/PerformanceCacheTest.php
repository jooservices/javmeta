<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Movie;
use App\Repositories\MovieRepository;
use App\Services\Performance\PerformanceCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class PerformanceCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_warmer_refreshes_existing_values_and_readers_share_the_cache_keys(): void
    {
        Cache::put('javmeta:status:summary', ['old' => true], 15);
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
        config(['javmeta_perf.warm_enabled' => false]);
        $this->artisan('perf:warm')->assertSuccessful();
        $this->assertNull(Cache::get('javmeta:status:summary'));
        $this->assertNull(Cache::get('javmeta:facets:' . md5('[]')));
    }

    public function test_status_only_keeps_the_short_ttl_without_rebuilding_facets(): void
    {
        $this->artisan('perf:warm --status-only')->assertSuccessful();
        $this->assertNotNull(Cache::get('javmeta:status:summary'));
        $this->assertNull(Cache::get('javmeta:facets:' . md5('[]')));
        $this->travel(16)->seconds();
        $this->assertNull(Cache::get('javmeta:status:summary'));
    }

    public function test_one_failure_preserves_the_previous_cache_and_does_not_skip_the_other(): void
    {
        Cache::put('javmeta:status:summary', ['old' => true], 15);
        Schema::drop('crawl_runs');
        $this->artisan('perf:warm')->assertFailed();
        $this->assertSame(['old' => true], Cache::get('javmeta:status:summary'));
        $this->assertSame(0, app(PerformanceCache::class)->facets()['total']);
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
}
