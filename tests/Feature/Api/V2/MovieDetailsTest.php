<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use App\Services\Api\V2\Contracts\MovieViewCounter;
use Illuminate\Cache\RateLimiter;
use Tests\Support\Api\V2\FakeReadModelDatabase;
use Tests\Support\Api\V2\ReadModelRows;
use Tests\TestCase;

/** One movie over the v2 API: every sub-resource, the lookups, the facet autocomplete and the view counter, over canned rows. */
final class MovieDetailsTest extends TestCase
{
    private FakeReadModelDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.limiter' => 'array']);
        $this->app->forgetInstance(RateLimiter::class);
        $this->database = FakeReadModelDatabase::install();
    }

    public function test_the_detail_merges_the_summary_the_extras_and_the_cast_most_experienced_first(): void
    {
        $this->database
            ->answer('FROM movies WHERE id', [['description' => 'Plot', 'attrs' => '{"k":1}', 'uuid' => 'u-1', 'delisted_at' => null]])
            ->answer('p.id = ANY', [ReadModelRows::performer(['id' => 7, 'movie_count' => 3]), ReadModelRows::performer(['id' => 8, 'movie_count' => 30])])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $response = $this->getJson('/api/v2/movies/aaa-001')->assertOk();

        $response->assertJsonPath('data.code', 'AAA-001')->assertJsonPath('data.description', 'Plot')->assertJsonPath('data.uuid', 'u-1')->assertJsonPath('data.attrs', ['k' => 1])->assertJsonPath('data.delisted_at', null);
        self::assertSame([8, 7], array_column($response->json('data.performers'), 'id'));
    }

    public function test_a_movie_without_extras_or_cast_still_has_a_detail(): void
    {
        $this->database
            ->answer('FROM movies WHERE id', [])
            ->answer('m.code = ?', [ReadModelRows::movie(['performer_ids' => '{}'])]);

        $response = $this->getJson('/api/v2/movies/AAA-001')->assertOk();

        $response->assertJsonPath('data.description', null)->assertJsonPath('data.attrs', [])->assertJsonPath('data.performers', []);
    }

    public function test_an_unknown_movie_is_404_on_every_sub_resource(): void
    {
        $this->database->answer('m.code = ?', []);

        foreach (['', '/codes', '/sources', '/media', '/downloads', '/related'] as $suffix) {
            $this->getJson('/api/v2/movies/NOPE-1' . $suffix)->assertNotFound()->assertJsonPath('code', 'movie_not_found');
        }

        $this->postJson('/api/v2/movies/NOPE-1/views')->assertNotFound()->assertJsonPath('code', 'movie_not_found');
    }

    public function test_codes_carry_markers_for_review_delisting_and_derived_codes(): void
    {
        $this->database
            ->answer('FROM movie_codes', [
                ['code' => 'AAA-001', 'kind' => 'display', 'source_slug' => 'alpha', 'source_url' => 'https://a/1', 'crawled_at' => '2024-05-02 10:00:00'],
                ['code' => 'AAA001', 'kind' => 'derived', 'source_slug' => null, 'source_url' => null, 'crawled_at' => null],
            ])
            ->answer('m.code = ?', [ReadModelRows::movie(['needs_review' => true, 'delisted' => true])]);

        $response = $this->getJson('/api/v2/movies/AAA-001/codes')->assertOk();

        $response->assertJsonPath('meta.markers', ['needs_review', 'delisted', 'derived_code'])->assertJsonPath('data.1.crawled_at', null)->assertJsonPath('data.0.kind', 'display');
    }

    public function test_a_clean_movie_with_only_display_codes_has_no_markers(): void
    {
        $this->database
            ->answer('FROM movie_codes', [['code' => 'AAA-001', 'kind' => 'display', 'source_slug' => 'alpha', 'source_url' => 'u', 'crawled_at' => null]])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $this->getJson('/api/v2/movies/AAA-001/codes')->assertOk()->assertJsonPath('meta.markers', []);
    }

    public function test_sources_are_listed_with_their_crawl_time(): void
    {
        $this->database
            ->answer('FROM movie_sources', [['source' => 'alpha', 'url' => 'https://a/1', 'code' => 'AAA-001', 'crawled_at' => '2024-05-02 10:00:00']])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $this->getJson('/api/v2/movies/AAA-001/sources')->assertOk()->assertJsonPath('data.0.source', 'alpha')->assertJsonPath('data.0.url', 'https://a/1');
    }

    public function test_media_is_grouped_into_gallery_hls_and_samples(): void
    {
        $this->database
            ->answer('FROM movie_media', [
                ['kind' => 'gallery', 'url' => 'g1', 'meta' => null, 'source' => 'alpha'],
                ['kind' => 'image', 'url' => 'g2', 'meta' => '{"w":10}', 'source' => 'alpha'],
                ['kind' => 'hls', 'url' => 'h1', 'meta' => null, 'source' => 'beta'],
                ['kind' => 'sample', 'url' => 's1', 'meta' => null, 'source' => 'beta'],
            ])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $response = $this->getJson('/api/v2/movies/AAA-001/media')->assertOk();

        self::assertSame(['g1', 'g2'], array_column($response->json('data.gallery'), 'url'));
        self::assertSame(['w' => 10], $response->json('data.gallery.1.meta'));
        self::assertSame(['h1'], array_column($response->json('data.hls'), 'url'));
        self::assertSame(['s1'], array_column($response->json('data.samples'), 'url'));
    }

    public function test_downloads_tell_magnets_from_torrents_and_read_the_optional_metadata(): void
    {
        $this->database
            ->answer('kind = \'magnet\'', [
                ['url' => 'magnet:?xt=1', 'meta' => '{"name":"File","size":"1.4 GB","bytes":1500000000,"quality":"1080p"}', 'source' => 'alpha'],
                ['url' => 'https://t/x.torrent', 'meta' => null, 'source' => 'beta'],
            ])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $response = $this->getJson('/api/v2/movies/AAA-001/downloads')->assertOk();

        $response->assertJsonPath('data.0', ['type' => 'magnet', 'url' => 'magnet:?xt=1', 'name' => 'File', 'size' => '1.4 GB', 'bytes' => 1500000000, 'quality' => '1080p', 'source' => 'alpha']);
        $response->assertJsonPath('data.1', ['type' => 'torrent', 'url' => 'https://t/x.torrent', 'name' => null, 'size' => null, 'bytes' => null, 'quality' => null, 'source' => 'beta']);
    }

    public function test_related_movies_respect_the_limit_range(): void
    {
        $this->database
            ->answer('rel FROM rm_movies m, rm_movies s', [ReadModelRows::movie(['id' => 2, 'code' => 'BBB-002'])])
            ->answer('m.code = ?', [ReadModelRows::movie()]);

        $this->getJson('/api/v2/movies/AAA-001/related')->assertOk()->assertJsonPath('data.0.code', 'BBB-002');
        self::assertStringContainsString('LIMIT 12', $this->database->queries()[1]);

        $this->getJson('/api/v2/movies/AAA-001/related?limit=999')->assertOk();
        self::assertStringContainsString('LIMIT 24', $this->database->queries()[3]);

        $this->getJson('/api/v2/movies/AAA-001/related?limit=0')->assertOk();
        self::assertStringContainsString('LIMIT 12', $this->database->queries()[5]);
    }

    public function test_lookups_return_found_movies_once_in_request_order_and_name_the_missing_codes(): void
    {
        $this->database->answer('m.code = ANY', [ReadModelRows::movie(), ReadModelRows::movie(['id' => 2, 'code' => 'BBB-002'])]);

        $response = $this->postJson('/api/v2/movies/lookups', ['codes' => ['bbb-002', 'AAA-001', 'zzz-9', 'aaa-001']])->assertOk();

        self::assertSame(['BBB-002', 'AAA-001'], array_column($response->json('data'), 'code'));
        $response->assertJsonPath('meta.missing', ['zzz-9']);
    }

    public function test_facet_values_autocomplete_genres_and_columns_and_refuse_unknown_facets(): void
    {
        $this->database->answer('unnest(m.genres) g', [['value' => 'Drama', 'count' => '4']])->answer('FROM rm_movies WHERE maker', [['value' => 'Maker', 'count' => 2]]);

        $this->getJson('/api/v2/movies/facets/values?facet=genre&q=dra')->assertOk()->assertExactJson(['data' => [['value' => 'Drama', 'count' => 4]], 'meta' => []]);
        $this->getJson('/api/v2/movies/facets/values?facet=maker&limit=500')->assertOk()->assertJsonPath('data.0.value', 'Maker');
        self::assertStringContainsString('LIMIT 50', $this->database->queries()[1]);

        $this->getJson('/api/v2/movies/facets/values?facet=color')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
        $this->getJson('/api/v2/movies/facets/values')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
    }

    public function test_views_are_counted_for_a_trimmed_list_of_codes(): void
    {
        $this->app->instance(MovieViewCounter::class, $this->counter(['AAA-001' => 5]));

        $this->getJson('/api/v2/movies/views?codes=AAA-001,,%20BBB-002%20')->assertOk()->assertExactJson(['data' => ['AAA-001' => 5], 'meta' => []]);
    }

    public function test_a_view_list_nobody_has_viewed_is_an_empty_object_not_an_empty_array(): void
    {
        $this->app->instance(MovieViewCounter::class, $this->counter([]));

        self::assertSame('{"data":{},"meta":{}}', $this->getJson('/api/v2/movies/views?codes=AAA-001')->assertOk()->getContent());
    }

    public function test_recording_a_view_answers_201_with_the_count_a_location_and_the_viewer(): void
    {
        $this->database->answer('m.code = ?', [ReadModelRows::movie(['code' => 'AAA-001'])]);
        $counter = $this->counter([]);
        $this->app->instance(MovieViewCounter::class, $counter);

        $this->postJson('/api/v2/movies/aaa-001/views')->assertCreated()->assertJsonPath('data.count', 1)->assertHeader('Location', '/api/v2/movies/aaa-001');

        $user = User::factory()->make(['id' => 42]);
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');
        $token = (string) $this->app['session.store']->token();
        $this->actingAs($user, 'web')->postJson('/api/v2/movies/aaa-001/views', [], ['X-CSRF-Token' => $token])->assertCreated()->assertJsonPath('data.count', 2);

        self::assertSame([['AAA-001', null], ['AAA-001', 42]], $counter->recorded);
    }

    /** @param array<string, int> $counts */
    private function counter(array $counts): MovieViewCounter
    {
        return new class ($counts) implements MovieViewCounter {
            /** @var list<array{0: string, 1: ?int}> */
            public array $recorded = [];

            /** @param array<string, int> $counts */
            public function __construct(private readonly array $counts) {}

            public function record(string $code, ?int $userId): int
            {
                $this->recorded[] = [$code, $userId];

                return count($this->recorded);
            }

            public function counts(array $codes): array
            {
                return array_intersect_key($this->counts, array_flip($codes));
            }
        };
    }
}
