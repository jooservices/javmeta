<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\Movie;
use App\Models\MovieMedia;
use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use App\Services\Api\ResponseCacheEpoch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Mockery;
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
        $this->assertStringContainsString('s-maxage=', (string) $first->headers->get('Cache-Control'));

        $this->withHeaders(['If-None-Match' => $etag])
            ->get('/ui-api/status/summary')
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
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
        $this->assertGreaterThan($epoch, app(ResponseCacheEpoch::class)->current());
        $domainSelects = $this->countDomainSelects(fn() => $this->getJson('/api/v1/movies?per_page=50', $headers)->assertOk());
        $this->assertGreaterThan(0, $domainSelects, 'A dataset write must invalidate the warmed response.');
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

            if (str_contains($sql, 'api_keys') || str_contains($sql, 'api_usage_logs')) {
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
            if (str_starts_with($sql, 'select') && ! str_contains($sql, 'api_keys') && ! str_contains($sql, 'api_usage_logs')) {
                $count++;
            }
        });

        $request();
        $enabled = false;

        return $count;
    }
}
