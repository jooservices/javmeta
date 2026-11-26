<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Models\User;
use App\Services\Api\V2\Contracts\MovieViewCounter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/** Movie, performer, catalogue and photo detail operations of the v2 API against real PostgreSQL read models. */
final class CatalogueDetailsTest extends PostgresApiTestCase
{
    private Performer $aoi;

    private Performer $mio;

    private Movie $first;

    private Movie $second;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        RateLimiter::clear('movies-views');
        $this->createCatalogue();
    }

    private function movie(string $code, array $attributes): Movie
    {
        return Movie::factory()->create(['display_code' => $code, 'code_normalized' => str_replace('-', '', $code), 'completeness_tier' => 2, 'maker' => null, 'label' => null, 'series' => null, 'community_score' => null, 'cover_url' => null, 'description' => null, 'runtime_minutes' => null, 'attrs' => [], ...$attributes]);
    }

    private function createCatalogue(): void
    {
        $this->aoi = Performer::factory()->create([
            'name_romaji' => 'Aoi Test',
            'name_kanji' => '青井',
            'birth_date' => '1999-05-05',
            'height_cm' => 160,
            'cup' => 'D',
            'blood_type' => 'A',
            'location' => 'Tokyo',
            'bio_text' => 'Likes tests.',
            'attrs' => ['zodiac_sign' => 'Taurus', 'tags' => ['idol'], 'favorite_count_raw' => '42', 'extra' => ['hobby' => 'reading', 'rating_looks' => 9, 'movie_count' => 7]],
        ]);
        $this->mio = Performer::factory()->create(['name_romaji' => 'Mio Sample', 'cup' => 'B', 'location' => 'Osaka', 'image_url' => null]);
        $merged = Performer::factory()->create(['name_romaji' => 'Aoi Merged', 'canonical_performer_id' => $this->aoi->id, 'source_slug' => 'other-site', 'external_id' => 'x1', 'profile_url' => 'https://other.test/aoi', 'image_url' => null]);

        $this->first = $this->movie('AAA-001', ['title_en' => 'First alpha', 'release_date' => '2025-03-01', 'maker' => 'Maker One', 'series' => 'Series S', 'label' => 'Label L', 'cover_url' => 'https://img.test/a.jpg', 'community_score' => 8.5, 'runtime_minutes' => 100, 'description' => 'About alpha', 'attrs' => ['k' => 'v']]);
        $this->second = $this->movie('BBB-002', ['title_en' => 'Second beta', 'release_date' => '2025-04-01', 'maker' => 'Maker Two', 'series' => 'Series S', 'cover_url' => 'https://img.test/b.jpg', 'community_score' => 6.0]);
        $gone = $this->movie('CCC-003', ['title_en' => 'Gone gamma', 'release_date' => '2025-05-01', 'maker' => 'Maker One', 'delisted_at' => now()->subDay(), 'needs_review' => true]);

        foreach ([[$this->first, $this->aoi], [$this->second, $this->aoi], [$this->second, $this->mio], [$gone, $this->aoi]] as [$movie, $performer]) {
            MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $performer->id]);
        }

        $drama = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        $comedy = Genre::factory()->create(['label_normalized' => 'comedy', 'label_raw' => 'Comedy']);

        foreach ([[$this->first, $drama], [$this->second, $drama], [$this->second, $comedy]] as [$movie, $genre]) {
            MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id, 'source_slug' => 's1']);
        }

        MovieCode::factory()->create(['movie_id' => $this->first->id, 'code' => 'AAA-001', 'code_normalized' => 'AAA001', 'kind' => 'dvd', 'source_slug' => 's1']);
        MovieCode::factory()->create(['movie_id' => $this->first->id, 'code' => 'AAA-001R', 'code_normalized' => 'AAA001R', 'kind' => 'derived', 'source_slug' => 's2']);
        DB::table('movie_sources')->insert(['movie_id' => $this->first->id, 'site_slug' => 's1', 'mongo_id' => 'm1', 'source_url' => 'https://s1.test/AAA001', 'source_code' => 'AAA-001', 'crawled_at' => now()]);

        MovieMedia::factory()->create(['movie_id' => $this->first->id, 'kind' => 'gallery', 'url' => 'https://img.test/g1.jpg', 'source_slug' => 's1']);
        MovieMedia::factory()->create(['movie_id' => $this->first->id, 'kind' => 'hls', 'url' => 'https://img.test/v.m3u8', 'source_slug' => 's1', 'meta' => ['quality' => '1080']]);
        MovieMedia::factory()->create(['movie_id' => $this->first->id, 'kind' => 'sample', 'url' => 'https://img.test/s.mp4', 'source_slug' => 's1']);
        MovieMedia::factory()->create(['movie_id' => $this->first->id, 'kind' => 'magnet', 'url' => 'magnet:?xt=urn:btih:abc', 'source_slug' => 'javdb', 'meta' => ['name' => 'AAA-001.torrent', 'size' => '2 GB', 'bytes' => 2147483648, 'quality' => 'HD']]);
        MovieMedia::factory()->create(['movie_id' => $this->first->id, 'kind' => 'magnet', 'url' => 'https://onejav.test/a.torrent', 'source_slug' => 'onejav']);

        PerformerMedia::factory()->create(['performer_id' => $this->aoi->id, 'kind' => 'image', 'url' => 'https://img.test/aoi.jpg', 'source_slug' => 's1']);
        PerformerMedia::factory()->create(['performer_id' => $merged->id, 'kind' => 'gallery', 'url' => 'https://img.test/aoi2.jpg', 'source_slug' => 'other-site']);
        DB::table('performer_sources')->insert(['performer_id' => $this->aoi->id, 'site_slug' => 's1', 'mongo_id' => 'p1', 'external_id' => 'e1', 'source_url' => 'https://s1.test/aoi', 'crawled_at' => now(), 'name_romaji' => 'Aoi Test']);

        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
    }

    public function test_movie_facet_values_autocomplete_one_facet_and_reject_an_unknown_one(): void
    {
        $this->getJson('/api/v2/movies/facets/values?facet=genre&q=dra')->assertOk()->assertExactJson(['data' => [['value' => 'Drama', 'count' => 2]], 'meta' => []]);
        $this->getJson('/api/v2/movies/facets/values?facet=maker')->assertOk()->assertJsonPath('data.0', ['value' => 'Maker One', 'count' => 2]);
        $this->getJson('/api/v2/movies/facets/values?facet=series&limit=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/movies/facets/values?facet=label')->assertOk()->assertJsonPath('data.0.value', 'Label L');
        $this->getJson('/api/v2/movies/facets/values?facet=bogus')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
        $this->getJson('/api/v2/movies/facets/values')->assertStatus(400);
    }

    public function test_movie_lookups_return_found_movies_in_request_order_and_name_the_missing_ones(): void
    {
        $response = $this->postJson('/api/v2/movies/lookups', ['codes' => ['bbb-002', 'AAA-001', 'ZZZ-999', 'aaa-001']])->assertOk();

        self::assertSame(['BBB-002', 'AAA-001'], array_column($response->json('data'), 'code'));
        $response->assertJsonPath('meta.missing', ['ZZZ-999']);

        $this->postJson('/api/v2/movies/lookups', ['codes' => []])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->postJson('/api/v2/movies/lookups', [])->assertStatus(422)->assertJsonFragment(['field' => 'codes', 'code' => 'required']);
    }

    public function test_movie_lookups_need_no_csrf_token_even_when_signed_in(): void
    {
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v2/movies/lookups', ['codes' => ['AAA-001']])->assertOk();
        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v2/performers/lookups', ['ids' => [$this->aoi->id]])->assertOk();
    }

    public function test_view_counts_come_from_the_bound_counter(): void
    {
        $this->getJson('/api/v2/movies/views?codes=AAA-001,BBB-002')->assertOk()->assertExactJson(['data' => ['AAA-001' => 0, 'BBB-002' => 0], 'meta' => []]);
        $this->getJson('/api/v2/movies/views?codes=,')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/movies/views')->assertStatus(422)->assertJsonFragment(['field' => 'codes', 'code' => 'required']);
    }

    public function test_view_counts_of_a_bound_counter_are_returned(): void
    {
        $this->app->instance(MovieViewCounter::class, new class implements MovieViewCounter {
            public function record(string $code, ?int $userId): int
            {
                return 7;
            }

            public function counts(array $codes): array
            {
                return array_fill_keys($codes, 3);
            }
        });

        $this->getJson('/api/v2/movies/views?codes=AAA-001')->assertOk()->assertJsonPath('data.AAA-001', 3);
    }

    public function test_counting_a_view_answers_201_with_the_total_and_a_location(): void
    {
        $recorded = [];
        $this->app->instance(MovieViewCounter::class, new class ($recorded) implements MovieViewCounter {
            /** @param array<int, array{0: string, 1: ?int}> $recorded */
            public function __construct(private array &$recorded) {}

            public function record(string $code, ?int $userId): int
            {
                $this->recorded[] = [$code, $userId];

                return count($this->recorded);
            }

            public function counts(array $codes): array
            {
                return [];
            }
        });

        $user = User::factory()->create();
        $this->postJson('/api/v2/movies/aaa-001/views')->assertCreated()->assertHeader('Location', '/api/v2/movies/aaa-001')->assertJsonPath('data.count', 1);
        $this->postJson('/api/v2/movies/NOPE-000/views')->assertNotFound()->assertJsonPath('code', 'movie_not_found');
        $token = $this->csrf($user);
        $this->actingAs($user, 'web')->postJson('/api/v2/movies/AAA-001/views', [], ['X-CSRF-Token' => $token])->assertCreated()->assertJsonPath('data.count', 2);

        self::assertSame([['AAA-001', null], ['AAA-001', $user->id]], $recorded);
        $this->actingAs($user, 'web')->postJson('/api/v2/movies/AAA-001/views')->assertForbidden()->assertJsonPath('code', 'csrf_mismatch');
    }

    public function test_counting_views_is_limited_to_sixty_a_minute(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->postJson('/api/v2/movies/AAA-001/views')->assertCreated();
        }

        $this->postJson('/api/v2/movies/AAA-001/views')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    }

    private function csrf(User $user): string
    {
        $this->actingAs($user, 'web')->getJson('/api/v2/sessions/current');

        return (string) $this->app['session.store']->token();
    }

    public function test_the_movie_detail_adds_description_attrs_cast_and_delisting_to_the_summary(): void
    {
        $response = $this->getJson('/api/v2/movies/AAA-001')->assertOk();

        $response->assertJsonPath('data.code', 'AAA-001')->assertJsonPath('data.title', 'First alpha')->assertJsonPath('data.description', 'About alpha')->assertJsonPath('data.attrs', ['k' => 'v'])->assertJsonPath('data.delisted_at', null);
        $response->assertJsonPath('data.performers.0.name', 'Aoi Test');
        self::assertSame($this->first->uuid, $response->json('data.uuid'));

        $this->getJson('/api/v2/movies/aaa001')->assertOk()->assertJsonPath('data.code', 'AAA-001');
        $this->getJson('/api/v2/movies/CCC-003')->assertOk()->assertJsonPath('data.delisted', true);
        $this->getJson('/api/v2/movies/BBB-002')->assertOk()->assertJsonPath('data.attrs', [])->assertJsonCount(2, 'data.performers');
        $this->getJson('/api/v2/movies/NOPE-000')->assertNotFound()->assertJsonPath('code', 'movie_not_found');
    }

    public function test_movie_codes_sources_media_and_downloads(): void
    {
        $codes = $this->getJson('/api/v2/movies/AAA-001/codes')->assertOk();
        self::assertSame(['AAA-001R', 'AAA-001'], array_column($codes->json('data'), 'code'));
        $codes->assertJsonPath('meta.markers', ['derived_code']);
        $this->getJson('/api/v2/movies/CCC-003/codes')->assertOk()->assertJsonPath('meta.markers', ['needs_review', 'delisted']);

        $this->getJson('/api/v2/movies/AAA-001/sources')->assertOk()->assertJsonPath('data.0.source', 's1')->assertJsonPath('data.0.url', 'https://s1.test/AAA001')->assertJsonPath('data.0.code', 'AAA-001');

        $media = $this->getJson('/api/v2/movies/AAA-001/media')->assertOk();
        $media->assertJsonPath('data.gallery.0.url', 'https://img.test/g1.jpg')->assertJsonPath('data.hls.0.meta', ['quality' => '1080'])->assertJsonPath('data.samples.0.url', 'https://img.test/s.mp4');

        $downloads = $this->getJson('/api/v2/movies/AAA-001/downloads')->assertOk();
        $downloads->assertJsonPath('data.0.type', 'magnet')->assertJsonPath('data.0.bytes', 2147483648)->assertJsonPath('data.0.size', '2 GB')->assertJsonPath('data.0.quality', 'HD')->assertJsonPath('data.0.name', 'AAA-001.torrent');
        $downloads->assertJsonPath('data.1.type', 'torrent')->assertJsonPath('data.1.bytes', null)->assertJsonPath('data.1.source', 'onejav');

        foreach (['codes', 'sources', 'media', 'downloads', 'related'] as $part) {
            $this->getJson("/api/v2/movies/NOPE-000/{$part}")->assertNotFound()->assertJsonPath('code', 'movie_not_found');
        }
    }

    public function test_related_movies_share_a_series_or_a_performer_and_skip_the_movie_itself_and_delisted_ones(): void
    {
        $related = $this->getJson('/api/v2/movies/AAA-001/related')->assertOk();

        self::assertSame(['BBB-002'], array_column($related->json('data'), 'code'));
        $this->getJson('/api/v2/movies/AAA-001/related?limit=999')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/movies/AAA-001/related?limit=0')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_performer_movie_facets_count_performers_per_movie_side_option(): void
    {
        $facets = $this->getJson('/api/v2/performers/movie-facets')->assertOk();

        $facets->assertJsonFragment(['name' => 'Drama', 'n' => 2]);
        self::assertSame([2025, 2025], $facets->json('data.years'));

        $narrowed = $this->getJson('/api/v2/performers/movie-facets?genre=Comedy&cup=B')->assertOk();
        $narrowed->assertJsonFragment(['name' => 'Maker Two', 'n' => 1]);
        self::assertSame(['Maker Two'], array_column($narrowed->json('data.makers'), 'name'));

        $this->getJson('/api/v2/performers/movie-facets?maker=Maker%20One&genre=Drama')->assertOk()->assertJsonFragment(['name' => 'Drama']);
    }

    public function test_performer_facet_values_autocomplete_and_lookups(): void
    {
        $this->getJson('/api/v2/performers/facets/values?facet=cup')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v2/performers/facets/values?facet=location&q=tok')->assertOk()->assertExactJson(['data' => [['value' => 'Tokyo', 'count' => 1]], 'meta' => []]);
        $this->getJson('/api/v2/performers/facets/values?facet=blood_type')->assertOk()->assertJsonPath('data.0.value', 'A');
        $this->getJson('/api/v2/performers/facets/values?facet=nope')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');

        $found = $this->postJson('/api/v2/performers/lookups', ['ids' => [$this->mio->id, $this->aoi->id, 99999, $this->mio->id]])->assertOk();
        self::assertSame(['Mio Sample', 'Aoi Test'], array_column($found->json('data'), 'name'));
        $this->postJson('/api/v2/performers/lookups', ['ids' => []])->assertStatus(422);
        $this->postJson('/api/v2/performers/lookups', ['ids' => ['x']])->assertStatus(422)->assertJsonFragment(['field' => 'ids.0', 'code' => 'integer']);
    }

    public function test_the_performer_profile_merges_bio_profile_fields_and_the_live_movie_count(): void
    {
        $response = $this->getJson("/api/v2/performers/{$this->aoi->id}-aoi-test")->assertOk();

        $response->assertJsonPath('data.name', 'Aoi Test')->assertJsonPath('data.bio_text', 'Likes tests.')->assertJsonPath('data.movie_count', 3)->assertJsonPath('meta.canonical_slug', "{$this->aoi->id}-aoi-test");
        $response->assertJsonPath('data.profile.hobby', 'reading')->assertJsonPath('data.profile.zodiac', 'Taurus')->assertJsonPath('data.profile.favorite_count', 42)->assertJsonPath('data.profile.tags', ['idol'])->assertJsonPath('data.profile.ratings', ['looks' => 9])->assertJsonPath('data.profile.filmography_count', 7)->assertJsonPath('data.profile.agency', null);

        $this->getJson("/api/v2/performers/{$this->mio->id}")->assertOk()->assertJsonPath('data.profile.ratings', [])->assertJsonPath('data.profile.favorite_count', null);
        $this->getJson('/api/v2/performers/999999')->assertNotFound()->assertJsonPath('code', 'performer_not_found');
        $this->getJson('/api/v2/performers/not-a-number')->assertNotFound()->assertJsonPath('code', 'performer_not_found');
    }

    public function test_performer_sources_photos_and_co_performers_include_the_merged_source_performers(): void
    {
        $sources = $this->getJson("/api/v2/performers/{$this->aoi->id}/sources")->assertOk();
        self::assertSame(3, count($sources->json('data')));
        self::assertContains('other-site', array_column($sources->json('data'), 'source'));
        $sources->assertJsonFragment(['url' => 'https://s1.test/aoi', 'active' => true]);

        $photos = $this->getJson("/api/v2/performers/{$this->aoi->id}/photos")->assertOk();
        self::assertSame(['gallery', 'image'], $this->sorted(array_column($photos->json('data'), 'kind')));

        $co = $this->getJson("/api/v2/performers/{$this->aoi->id}/co-performers")->assertOk();
        self::assertSame(['Mio Sample'], array_column($co->json('data'), 'name'));
        $co->assertJsonPath('data.0.together', 1);
        $this->getJson("/api/v2/performers/{$this->aoi->id}/co-performers?limit=500")->assertOk()->assertJsonCount(1, 'data');

        $this->getJson('/api/v2/performers/999999/sources')->assertNotFound()->assertJsonPath('code', 'performer_not_found');
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    public function test_performer_statistics_count_movies_by_year_maker_and_genre(): void
    {
        $stats = $this->getJson("/api/v2/performers/{$this->aoi->id}/statistics")->assertOk();

        $stats->assertJsonPath('data.by_year', [['year' => 2025, 'count' => 3]])->assertJsonPath('data.top_makers.0', ['name' => 'Maker One', 'count' => 2])->assertJsonPath('data.top_genres.0', ['name' => 'Drama', 'count' => 2]);
        $stats->assertJsonPath('data.span', ['first' => '2025-03-01', 'last' => '2025-05-01', 'total' => 3]);
    }

    public function test_performer_movies_are_paged_sorted_and_leave_out_delisted_ones(): void
    {
        $default = $this->getJson("/api/v2/performers/{$this->aoi->id}/movies")->assertOk();
        self::assertSame(['BBB-002', 'AAA-001'], array_column($default->json('data'), 'code'));
        $default->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.per_page', 12);

        self::assertSame(['AAA-001', 'BBB-002'], array_column($this->getJson("/api/v2/performers/{$this->aoi->id}/movies?sort=release_date")->json('data'), 'code'));
        self::assertSame(['AAA-001', 'BBB-002'], array_column($this->getJson("/api/v2/performers/{$this->aoi->id}/movies?sort=code")->json('data'), 'code'));
        self::assertSame(['AAA-001', 'BBB-002'], array_column($this->getJson("/api/v2/performers/{$this->aoi->id}/movies?sort=-score")->json('data'), 'code'));
        $this->getJson("/api/v2/performers/{$this->aoi->id}/movies?per_page=1&page=2")->assertOk()->assertJsonPath('data.0.code', 'AAA-001')->assertJsonPath('meta.pagination.pages', 2);
        $this->getJson("/api/v2/performers/{$this->aoi->id}/movies?sort=nonsense")->assertStatus(422)->assertJsonFragment(['field' => 'sort']);
    }

    public function test_the_catalogue_kinds_and_entries_are_listed_filtered_sorted_and_paged(): void
    {
        $kinds = $this->getJson('/api/v2/catalogs')->assertOk();
        self::assertSame(['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood-types', 'locations'], array_column($kinds->json('data'), 'kind'));
        $kinds->assertJsonPath('data.0', ['kind' => 'genres', 'target' => 'movies', 'filter' => 'genre'])->assertJsonPath('data.5', ['kind' => 'cups', 'target' => 'performers', 'filter' => 'cup']);

        $makers = $this->getJson('/api/v2/catalogs/makers')->assertOk();
        $makers->assertJsonPath('data.0', ['slug' => 'maker-two', 'name' => 'Maker Two', 'count' => 1, 'cover_url' => 'https://img.test/b.jpg', 'script' => 'latin', 'kind' => 'movies', 'filter' => 'maker']);
        $makers->assertJsonPath('meta.total_entries', 2)->assertJsonPath('meta.pagination.total', 2);

        self::assertSame(['Maker Two', 'Maker One'], array_column($this->getJson('/api/v2/catalogs/makers?sort=-name')->json('data'), 'name'));
        self::assertSame(['Maker One', 'Maker Two'], array_column($this->getJson('/api/v2/catalogs/makers?sort=name')->json('data'), 'name'));
        self::assertSame(['Maker One', 'Maker Two'], array_column($this->getJson('/api/v2/catalogs/makers?sort=count')->json('data'), 'name'));
        $this->getJson('/api/v2/catalogs/makers?q=two')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v2/catalogs/makers?letter=m&min_count=1&script=latin')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v2/catalogs/makers?script=ja')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v2/catalogs/makers?per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.pagination.pages', 2);
        $this->getJson('/api/v2/catalogs/genres')->assertOk()->assertJsonPath('data.0.name', 'Drama');
        $this->getJson('/api/v2/catalogs/series')->assertOk()->assertJsonPath('data.0.name', 'Series S');
        $this->getJson('/api/v2/catalogs/labels')->assertOk()->assertJsonPath('data.0.name', 'Label L');
        $this->getJson('/api/v2/catalogs/years')->assertOk()->assertJsonPath('data.0', ['slug' => '2025', 'name' => '2025', 'count' => 2, 'cover_url' => 'https://img.test/a.jpg', 'script' => 'latin', 'kind' => 'movies', 'filter' => 'released_from']);
        $this->getJson('/api/v2/catalogs/cups')->assertOk()->assertJsonPath('data.0.kind', 'performers')->assertJsonPath('meta.total_entries', 2);
        $this->getJson('/api/v2/catalogs/blood-types')->assertOk()->assertJsonPath('data.0.name', 'A');
        $this->getJson('/api/v2/catalogs/locations')->assertOk()->assertJsonPath('data.0.filter', 'location');
        $this->getJson('/api/v2/catalogs/nope')->assertNotFound()->assertJsonPath('code', 'catalog_not_found');
    }

    public function test_the_catalogue_index_groups_entries_by_script_and_first_letter(): void
    {
        $index = $this->getJson('/api/v2/catalogs/makers/index')->assertOk();

        $index->assertJsonPath('data.0', ['script' => 'latin', 'label' => 'Latin', 'count' => 2, 'letters' => [['letter' => 'M', 'count' => 2]]]);
        $this->getJson('/api/v2/catalogs/makers/index?min_count=2')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/catalogs/nope/index')->assertNotFound()->assertJsonPath('code', 'catalog_not_found');
    }

    public function test_one_catalogue_entry_is_found_by_slug_or_name(): void
    {
        $this->getJson('/api/v2/catalogs/makers/maker-two')->assertOk()->assertJsonPath('data.name', 'Maker Two');
        $this->getJson('/api/v2/catalogs/makers/Maker%20One')->assertOk()->assertJsonPath('data.slug', 'maker-one');
        $this->getJson('/api/v2/catalogs/makers/nobody')->assertNotFound()->assertJsonPath('code', 'catalog_entry_not_found');
        $this->getJson('/api/v2/catalogs/nope/anything')->assertNotFound()->assertJsonPath('code', 'catalog_not_found');
    }

    public function test_japanese_names_get_a_unicode_slug_and_their_own_script(): void
    {
        $this->movie('JPN-001', ['maker' => 'ソフト・オン・デマンド', 'release_date' => '2025-06-01', 'cover_url' => null]);
        $this->movie('ZHO-001', ['maker' => '漢字社', 'release_date' => '2025-06-02', 'cover_url' => null]);
        $this->movie('OTH-001', ['maker' => '#hash', 'release_date' => '2025-06-03', 'cover_url' => null]);
        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
        Cache::flush();

        $this->getJson('/api/v2/catalogs/makers?script=ja')->assertOk()->assertJsonPath('data.0.slug', 'ソフト-オン-デマンド')->assertJsonPath('data.0.script', 'ja');
        $this->getJson('/api/v2/catalogs/makers?script=zh')->assertOk()->assertJsonPath('data.0.name', '漢字社');
        $this->getJson('/api/v2/catalogs/makers?script=other')->assertOk()->assertJsonPath('data.0.slug', 'hash');
        self::assertSame(['latin', 'ja', 'zh', 'other'], array_column($this->getJson('/api/v2/catalogs/makers/index')->json('data'), 'script'));
    }

    public function test_the_photo_collection_lists_avatars_and_covers_with_totals(): void
    {
        $avatars = $this->getJson('/api/v2/photos')->assertOk();
        $avatars->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.kind', 'avatar')->assertJsonPath('data.0.label', 'Aoi Test')->assertJsonPath('data.0.performer_id', $this->aoi->id)->assertJsonPath('data.0.full_url', 'https://img.test/aoi.jpg');

        $covers = $this->getJson('/api/v2/photos?kind=cover')->assertOk();
        $covers->assertJsonPath('meta.pagination.total', 2)->assertJsonPath('meta.pagination.per_page', 48)->assertJsonPath('data.0.kind', 'cover')->assertJsonPath('data.0.thumb_url', 'https://img.test/b.jpg')->assertJsonPath('data.0.label', 'Second beta');
        $this->getJson('/api/v2/photos?kind=cover&q=alpha')->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.movie_code', 'AAA-001');
        $this->getJson('/api/v2/photos?kind=avatar&q=nobody')->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v2/photos?kind=other')->assertStatus(422)->assertJsonFragment(['field' => 'kind']);
        $this->getJson('/api/v2/photos?kind=cover&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data');
    }
}
