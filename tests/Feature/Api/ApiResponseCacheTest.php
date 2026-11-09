<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Data\Crawl\PerformerDraft;
use App\Models\Movie;
use App\Models\MovieMedia;
use App\Models\PerformerSource;
use App\Models\User;
use App\Services\Api\ApiResponseCache;
use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use App\Services\Api\ResponseCacheEpoch;
use App\Services\Persist\GalleryPersister;
use App\Services\Persist\PerformerPersister;
use App\Services\Settings\SettingsService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class ApiResponseCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'javmeta_response_cache.store' => 'array',
            'javmeta.ui_login_required' => false,
        ]);
        Cache::store('array')->flush();
        DependencyMonitorTestHelper::bind();
    }

    public function test_warm_responses_skip_domain_selects_for_the_qa_dataset(): void
    {
        app(SampleDataset::class)->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create(
                'response-cache-query-count',
                60,
                ['movies', 'performers', 'catalog', 'status'],
            )->plaintext,
        ];

        $paths = [
            '/api/v1/movies?per_page=50',
            '/api/v1/performers?per_page=50',
            '/api/v1/movies/facets',
            '/api/v1/performers/facets',
            '/api/v1/meta/catalog/makers',
            '/api/v1/suggest?q=sample',
            '/api/v1/status/summary',
            '/api/v1/movies/SMP-0001',
        ];

        $failures = [];
        $measurements = [];
        foreach ($paths as $path) {
            [$coldReads, $warmReads] = $this->domainSelectCounts(function () use ($path, $headers): void {
                $this->getJson($path, $headers)->assertOk();
            });

            $measurements[] = "{$path}: cold={$coldReads}, warm={$warmReads}";
            if ($coldReads < 1 || $warmReads !== 0) {
                $failures[] = end($measurements);
            }
        }

        $this->assertSame([], $failures, 'Expected each warmed response to skip domain SELECTs. Measured: ' . implode('; ', $measurements));
    }

    public function test_guest_ui_bridge_emits_an_etag_and_honours_if_none_match(): void
    {
        app(SampleDataset::class)->load();

        $first = $this->getJson('/ui-api/status/summary')->assertOk();
        $etag = $first->headers->get('ETag');

        $this->assertIsString($etag);
        $this->assertNotSame('', $etag);
        $first->assertHeader('Vary', 'Accept-Language');
        $this->assertStringContainsString('private', (string) $first->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $first->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage=', (string) $first->headers->get('Cache-Control'));

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/ui-api/status/summary')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
    }

    public function test_head_does_not_store_an_empty_representation_for_a_following_get(): void
    {
        app(SampleDataset::class)->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-head', 60, ['movies'])->plaintext,
        ];

        $this->head('/api/v1/movies?per_page=10', $headers)->assertOk();

        $response = $this->getJson('/api/v1/movies?per_page=10', $headers)->assertOk();
        $this->assertNotSame('', (string) $response->getContent());
        $response->assertJsonPath('meta.pagination.total', 520);
    }

    public function test_ui_calendar_months_and_invalid_months_bypass_the_response_cache(): void
    {
        $store = $this->useSwitchableResponseCacheStore();
        config(['javmeta_response_cache.store' => 'response-cache-switchable']);
        Movie::factory()->create(['display_code' => 'CAL-OCT', 'release_date' => '2026-10-03']);
        Movie::factory()->create(['display_code' => 'CAL-NOV', 'release_date' => '2026-11-03']);

        $october = $this->getJson('/ui-api/movies/calendar?month=2026-10')->assertOk();
        $november = $this->getJson('/ui-api/movies/calendar?month=2026-11')->assertOk();
        $october->assertJsonPath('meta.month', '2026-10')->assertJsonFragment(['code' => 'CAL-OCT']);
        $october->assertJsonMissing(['code' => 'CAL-NOV']);
        $november->assertJsonPath('meta.month', '2026-11')->assertJsonFragment(['code' => 'CAL-NOV']);
        $november->assertJsonMissing(['code' => 'CAL-OCT']);
        $this->getJson('/ui-api/movies/calendar?month=2026-13')->assertStatus(400);
        $this->assertSame(0, $store->getCalls, 'Calendar requests must not be classified as movie detail cache entries.');
    }

    public function test_guest_response_is_not_shared_cacheable_before_login_is_enabled(): void
    {
        $publicResponse = $this->getJson('/ui-api/meta/catalog/makers')->assertOk();
        $this->assertStringContainsString('private', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', (string) $publicResponse->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('s-maxage=', (string) $publicResponse->headers->get('Cache-Control'));

        config(['javmeta.ui_login_required' => true]);

        $this->getJson('/ui-api/meta/catalog/makers')->assertUnauthorized();
    }

    public function test_owner_ui_responses_are_never_publicly_cacheable(): void
    {
        app(SampleDataset::class)->load();
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $response = $this->actingAs($owner)->getJson('/ui-api/status/summary')->assertOk();
        $cacheControl = (string) $response->headers->get('Cache-Control');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringNotContainsString('public', $cacheControl);
    }

    public function test_guest_and_owner_ui_responses_never_share_a_cache_entry(): void
    {
        app(SampleDataset::class)->load();
        config(['javmeta_response_cache.slots_per_class' => 1]);
        $movie = Movie::query()->where('display_code', 'SMP-0001')->firstOrFail();
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => MovieMedia::KIND_GALLERY, 'url' => 'https://images.example/gallery.jpg']);
        MovieMedia::factory()->create(['movie_id' => $movie->id, 'kind' => MovieMedia::KIND_HLS, 'url' => 'https://video.example/private.m3u8']);
        $owner = User::factory()->create(['role' => 'super_administrator']);

        $guest = $this->getJson('/ui-api/movies/SMP-0001')->assertOk();
        $guest->assertJsonMissingPath('data.gallery');
        $guest->assertJsonMissingPath('data.hls_stream_urls');

        $ownerResponse = $this->actingAs($owner)->getJson('/ui-api/movies/SMP-0001')->assertOk();
        $ownerResponse->assertJsonPath('data.gallery.0.url', 'https://images.example/gallery.jpg');
        $ownerResponse->assertJsonPath('data.hls_stream_urls.0.url', 'https://video.example/private.m3u8');
        $this->assertStringContainsString('private', (string) $ownerResponse->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', (string) $ownerResponse->headers->get('Cache-Control'));
    }

    public function test_api_keys_with_different_scopes_cannot_share_a_response_entry(): void
    {
        app(SampleDataset::class)->load();
        config(['javmeta_response_cache.slots_per_class' => 1]);
        $moviesKey = app(ApiKeyService::class)->create('response-cache-movies', 60, ['movies']);
        $catalogKey = app(ApiKeyService::class)->create('response-cache-catalog', 60, ['movies', 'catalog']);
        $this->getJson('/api/v1/movies?per_page=50', ['X-API-Key' => $moviesKey->plaintext])->assertOk();

        $domainSelects = $this->countDomainSelects(fn() => $this->getJson(
            '/api/v1/movies?per_page=50',
            ['X-API-Key' => $catalogKey->plaintext],
        )->assertOk());
        $this->assertGreaterThan(0, $domainSelects, 'A distinct API key and scope set must get an isolated cache entry.');
    }

    public function test_expired_entries_are_rebuilt_after_the_fresh_ttl(): void
    {
        app(SampleDataset::class)->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-ttl', 60, ['movies'])->plaintext,
        ];
        $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk();
        $this->travel(6)->seconds();

        $domainSelects = $this->countDomainSelects(fn() => $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk());
        $this->assertGreaterThan(0, $domainSelects, 'Expired response entries must not be returned as fresh hits.');
    }

    public function test_response_cache_setting_bypasses_reads_and_writes(): void
    {
        app(SampleDataset::class)->load();
        config(['javmeta_response_cache.enabled' => false]);
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-disabled', 60, ['movies'])->plaintext,
        ];

        $firstSelects = $this->countDomainSelects(fn() => $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk());
        $secondSelects = $this->countDomainSelects(fn() => $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk());
        $this->assertGreaterThan(0, $firstSelects);
        $this->assertGreaterThan(0, $secondSelects);
    }

    public function test_dataset_writes_bump_the_epoch_and_invalidate_cached_responses(): void
    {
        $dataset = app(SampleDataset::class);
        $dataset->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-epoch', 60, ['movies'])->plaintext,
        ];
        $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk();
        $epoch = app(ResponseCacheEpoch::class)->current();

        $dataset->load();
        $this->assertNotSame($epoch, app(ResponseCacheEpoch::class)->current());
        $domainSelects = $this->countDomainSelects(fn() => $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk());
        $this->assertGreaterThan(0, $domainSelects, 'A dataset write must invalidate the warmed response.');
    }

    public function test_epoch_uses_the_primary_database_even_when_the_default_cache_connection_differs(): void
    {
        config(['cache.stores.database.connection' => 'non-primary-cache-connection']);
        Cache::purge('database');

        $epoch = app(ResponseCacheEpoch::class);
        $current = $epoch->current();
        $epoch->bump();

        $this->assertNotSame($current, $epoch->current());
    }

    public function test_cache_store_failure_falls_back_to_the_endpoint_response(): void
    {
        app(SampleDataset::class)->load();
        Cache::extend('response-cache-failure', function ($app, array $config) {
            $store = Mockery::mock(Store::class);
            $store->shouldReceive('get')->andThrow(new RuntimeException('cache unavailable'));

            return $this->repository($store, $config);
        });
        config([
            'cache.stores.response-cache-failure' => ['driver' => 'response-cache-failure'],
            'javmeta_response_cache.store' => 'response-cache-failure',
        ]);
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-failure', 60, ['movies'])->plaintext,
        ];

        $response = $this->getJson('/api/v1/movies?per_page=50', $headers);
        $response->assertOk()->assertJsonPath('meta.pagination.total', 520);
    }

    public function test_warm_cache_does_not_skip_movie_search_or_facet_validation(): void
    {
        $store = $this->useSwitchableResponseCacheStore();
        config(['javmeta_response_cache.store' => 'response-cache-switchable']);
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-validation', 60, ['movies'])->plaintext,
        ];

        $this->getJson('/api/v1/movies?per_page=10', $headers)->assertOk();
        $store->getCalls = 0;
        $this->getJson('/api/v1/movies?per_page=10.9', $headers)->assertStatus(400);
        $this->getJson('/api/v1/movies?per_page=10.9', $headers)->assertStatus(400);
        $this->assertSame(0, $store->getCalls, 'Invalid movie list requests must be rejected before the response cache is read.');

        $this->getJson('/api/v1/movies/facets', $headers)->assertOk();
        $store->getCalls = 0;
        $this->getJson('/api/v1/movies/facets?sort=invalid', $headers)->assertStatus(400);
        $this->assertSame(0, $store->getCalls, 'Invalid facet requests must be rejected before the response cache is read.');
    }

    public function test_cursor_validation_distinguishes_an_omitted_sort_from_an_explicit_sort(): void
    {
        app(SampleDataset::class)->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-cursor-sort', 60, ['movies'])->plaintext,
        ];
        $first = $this->getJson('/api/v1/movies?sort=release_date&per_page=1', $headers)->assertOk();
        $cursor = (string) $first->json('meta.pagination.next_cursor');
        $cursorPath = '/api/v1/movies?per_page=1&cursor=' . urlencode($cursor);

        $this->getJson($cursorPath, $headers)->assertOk();
        $this->getJson($cursorPath . '&sort=relevance', $headers)->assertStatus(400);
    }

    public function test_facet_response_ttl_rebuilds_after_the_configured_ttl(): void
    {
        app(SampleDataset::class)->load();
        config([
            'javmeta_response_cache.ttl.facets' => 1,
            'javmeta_response_cache.stale_seconds' => 0,
        ]);
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-facet-ttl', 60, ['movies'])->plaintext,
        ];

        $this->getJson('/api/v1/movies/facets', $headers)->assertOk()->assertJsonPath('data.total', 520);
        Movie::factory()->create(['maker' => 'Added after facet cache warm']);
        $this->travel(2)->seconds();

        $this->getJson('/api/v1/movies/facets', $headers)->assertOk()->assertJsonPath('data.total', 521);
    }

    public function test_location_alias_setting_changes_bump_the_response_epoch(): void
    {
        $store = new FakeConfigStore();
        $this->app->instance(ConfigStore::class, $store);
        $this->app->forgetInstance(SettingsService::class);
        $settings = app(SettingsService::class);
        $epoch = app(ResponseCacheEpoch::class);
        $before = $epoch->current();

        $settings->set('normalization.location_aliases', ['tokyo' => '東京都', '東京都' => '東京都']);

        $this->assertNotSame($before, $epoch->current());
    }

    public function test_sample_dataset_wipe_invalidates_the_cached_movie_list(): void
    {
        $dataset = app(SampleDataset::class);
        $dataset->load();
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-wipe', 60, ['movies'])->plaintext,
        ];
        $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk()->assertJsonPath('meta.pagination.total', 520);

        $dataset->wipe();

        $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_gallery_write_during_response_cache_outage_invalidates_movie_and_performer_responses(): void
    {
        app(SampleDataset::class)->load();
        $cacheStore = $this->useSwitchableResponseCacheStore();
        config(['javmeta_response_cache.store' => 'response-cache-switchable']);
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-gallery', 60, ['movies', 'performers'])->plaintext,
        ];

        $this->getJson('/api/v1/movies/SMP-0001', $headers)->assertOk()->assertJsonPath('data.gallery', []);
        $beforePerformers = $this->getJson('/api/v1/performers?per_page=100', $headers)->assertOk()->json('meta.pagination.total');

        $cacheStore->unavailable = true;
        app(GalleryPersister::class)->persist('eporner', 'https://eporner.test/review-gallery', new GalleryDto(
            externalId: 'review-gallery',
            title: 'Review Gallery',
            performers: ['Review Cache Performer'],
            photos: [new PhotoDto(id: 'review-photo', imageUrl: 'https://images.example/review-photo.jpg', thumbnailUrl: null, position: 1)],
            metadata: ['movie_code' => 'SMP-0001'],
        ));
        $cacheStore->unavailable = false;

        $this->getJson('/api/v1/movies/SMP-0001', $headers)
            ->assertOk()
            ->assertJsonPath('data.gallery.0.url', 'https://images.example/review-photo.jpg');
        $afterPerformers = $this->getJson('/api/v1/performers?per_page=100', $headers)
            ->assertOk()
            ->json('meta.pagination.total');
        $this->assertSame($beforePerformers + 1, $afterPerformers);
    }

    public function test_performer_epoch_changes_after_source_reconciliation_finishes(): void
    {
        config(['elasticsearch.enabled' => false]);
        app(PerformerPersister::class)->persistPerformer(PerformerDraft::from(
            'javdb',
            'p1',
            'Aoi Sakura',
            birthDate: '1990-01-01',
        ));
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-performer-order', 60, ['performers'])->plaintext,
        ];
        app(ResponseCacheEpoch::class)->current();
        $interleavedTotal = null;
        $eventName = 'eloquent.saved: ' . PerformerSource::class;
        Event::listen($eventName, function (PerformerSource $source) use (&$interleavedTotal, $headers): void {
            if ($source->site_slug === 'onejav' && $interleavedTotal === null) {
                $interleavedTotal = $this->getJson('/api/v1/performers?per_page=100', $headers)
                    ->assertOk()
                    ->json('meta.pagination.total');
            }
        });

        try {
            app(PerformerPersister::class)->persistPerformer(PerformerDraft::from(
                'onejav',
                'x9',
                'Sakura Aoi',
                birthDate: '1990-01-01',
            ));
        } finally {
            Event::forget($eventName);
        }

        $this->assertSame(2, $interleavedTotal, 'The controlled interleaving should observe the pre-reconciliation rows.');
        $this->getJson('/api/v1/performers?per_page=100', $headers)
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    public function test_default_cache_outage_uses_the_durable_rate_limiter_fallback_for_api_and_guest_status(): void
    {
        $cacheStore = $this->useSwitchableResponseCacheStore();
        config([
            'cache.default' => 'response-cache-switchable',
            'cache.limiter' => 'database',
            'javmeta_response_cache.store' => 'array',
            'javmeta.ui_login_required' => false,
            'javmeta.ui_guest_rate_limit_per_minute' => 1,
        ]);
        $cacheStore->unavailable = true;
        $headers = [
            'X-API-Key' => app(ApiKeyService::class)->create('response-cache-rate-limiter', 1, ['status'])->plaintext,
        ];

        $this->getJson('/api/v1/status/summary', $headers)->assertOk();
        $this->getJson('/api/v1/status/summary', $headers)->assertStatus(429);
        $this->getJson('/ui-api/status/summary')
            ->assertOk();
        $this->getJson('/ui-api/status/summary')->assertStatus(429);

        $owner = User::factory()->create(['role' => 'super_administrator']);
        $this->actingAs($owner)
            ->getJson('/ui-api/status/summary')
            ->assertOk()
            ->assertJsonPath('data.workers_total', 0);
    }

    public function test_cold_cache_waiter_does_not_run_a_second_builder_after_lock_wait(): void
    {
        config([
            'javmeta_response_cache.store' => 'array',
            'javmeta_response_cache.slots_per_class' => 1,
            'javmeta_response_cache.lock_seconds' => 1,
        ]);
        $apiKey = (object) ['id' => 'lock-collision', 'scopes' => ['movies']];
        $builderCalls = 0;
        $waiterResponse = null;
        $startedAt = microtime(true);

        $response = $this->runCachedApiRequest($apiKey, '/api/v1/movies?per_page=10', function () use ($apiKey, &$builderCalls, &$waiterResponse): SymfonyResponse {
            $builderCalls++;
            $waiterResponse = $this->runCachedApiRequest(
                $apiKey,
                '/api/v1/movies?per_page=20',
                function () use (&$builderCalls): SymfonyResponse {
                    $builderCalls++;

                    return new SymfonyResponse('{"waiter":true}', 200, ['Content-Type' => 'application/json']);
                },
            );

            return new SymfonyResponse('{"builder":true}', 200, ['Content-Type' => 'application/json']);
        });

        $this->assertSame(1, $builderCalls);
        $this->assertInstanceOf(SymfonyResponse::class, $waiterResponse);
        $this->assertSame(503, $waiterResponse->getStatusCode());
        $this->assertSame(200, $response->getStatusCode());
        $this->assertGreaterThanOrEqual(0.7, microtime(true) - $startedAt, 'The waiter should outlast the response rebuild wait without becoming a second builder.');
    }

    /** @return array{int, int} cold/warm SELECT counts against domain tables only */
    private function domainSelectCounts(callable $request): array
    {
        $count = 0;
        $enabled = true;
        DB::listen(static function ($query) use (&$count, &$enabled): void {
            if (! $enabled) {
                return;
            }

            $sql = strtolower(ltrim($query->sql));
            if (! str_starts_with($sql, 'select')) {
                return;
            }

            if (str_contains($sql, 'api_keys') || str_contains($sql, 'api_usage_logs') || self::isCacheStoreQuery($sql)) {
                return;
            }

            $count++;
        });

        $request();
        $cold = $count;
        $count = 0;
        $request();
        $enabled = false;

        return [$cold, $count];
    }

    private function countDomainSelects(callable $request): int
    {
        [$count, $enabled] = [0, true];
        DB::listen(static function ($query) use (&$count, &$enabled): void {
            if (! $enabled) {
                return;
            }

            $sql = strtolower(ltrim($query->sql));
            if (str_starts_with($sql, 'select') && ! str_contains($sql, 'api_keys') && ! str_contains($sql, 'api_usage_logs') && ! self::isCacheStoreQuery($sql)) {
                $count++;
            }
        });

        $request();
        $enabled = false;

        return $count;
    }

    private static function isCacheStoreQuery(string $sql): bool
    {
        return preg_match('/\bfrom\s+["`]?cache["`]?\b/', $sql) === 1;
    }

    private function useSwitchableResponseCacheStore(): object
    {
        $store = new class extends ArrayStore {
            public bool $unavailable = false;
            public int $getCalls = 0;

            public function get($key, $default = null): mixed
            {
                $this->getCalls++;
                if ($this->unavailable) {
                    throw new RuntimeException('cache unavailable');
                }

                return parent::get($key, $default);
            }

            public function put($key, $value, $seconds): bool
            {
                if ($this->unavailable) {
                    throw new RuntimeException('cache unavailable');
                }

                return parent::put($key, $value, $seconds);
            }

            public function add($key, $value, $seconds): bool
            {
                if ($this->unavailable) {
                    throw new RuntimeException('cache unavailable');
                }

                return parent::add($key, $value, $seconds);
            }

            public function increment($key, $value = 1): int|false
            {
                if ($this->unavailable) {
                    throw new RuntimeException('cache unavailable');
                }

                return parent::increment($key, $value);
            }
        };

        Cache::extend('response-cache-switchable', static fn($app, array $config) => Cache::repository($store, $config));
        config(['cache.stores.response-cache-switchable' => ['driver' => 'response-cache-switchable']]);

        return $store;
    }

    private function runCachedApiRequest(object $apiKey, string $uri, callable $build): SymfonyResponse
    {
        $request = HttpRequest::create($uri, 'GET');
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(static fn() => $route);
        $request->attributes->set('api_key', $apiKey);

        return app(ApiResponseCache::class)->handleApi($request, $build);
    }
}
