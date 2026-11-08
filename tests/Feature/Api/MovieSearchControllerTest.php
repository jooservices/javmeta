<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\Movie;
use App\Models\MovieGenre;
use App\Models\MoviePerformer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class MovieSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_COUNT = 5000;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
        DependencyMonitorTestHelper::bind();
    }

    public function test_movies_require_api_key(): void
    {
        $this->getJson('/api/v1/movies')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_q_matches_title_and_code_tokens(): void
    {
        Movie::factory()->create(['title_en' => 'NebulaZero Protocol']);
        Movie::factory()->create(['display_code' => 'SSIS-777', 'code_normalized' => 'SSIS777', 'title_en' => null, 'title_jp' => null]);

        $response = $this->getJson('/api/v1/movies?q=' . urlencode('nebulazero'), [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.title_en', 'NebulaZero Protocol');

        $byCode = $this->getJson('/api/v1/movies?q=SSIS777', [
            'X-API-Key' => $this->apiKey,
        ]);

        $byCode->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.code', 'SSIS-777');
    }

    public function test_semantic_mode_uses_semantic_search_and_requires_a_query(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Quiet Harbor']);

        $this->getJson('/api/v1/movies?q=quiet%20harbor&semantic=true', [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.title_en', 'Quiet Harbor')
            ->assertJsonPath('meta.pagination.sort', 'relevance')
            ->assertJsonPath('meta.pagination.next_cursor', null);

        $this->getJson('/api/v1/movies?q=quiet%20harbor&semantic=false', [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.title_en', 'Quiet Harbor');

        $this->getJson('/api/v1/movies?semantic=true', [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');

        $this->getJson('/api/v1/movies?q=quiet&semantic=true&genre=drama', [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_disabled_search_returns_database_results_when_elasticsearch_is_unavailable(): void
    {
        $movie = Movie::factory()->create(['title_en' => 'Quiet Harbor']);
        config()->set('elasticsearch.enabled', false);
        DependencyMonitorTestHelper::bind(['elasticsearch' => false]);

        $this->getJson('/api/v1/movies?q=quiet%20harbor', [
            'X-API-Key' => $this->apiKey,
        ])
            ->assertOk()
            ->assertJsonPath('data.0.title_en', $movie->title_en);
    }

    public function test_code_filter_matches_display_and_normalized_code(): void
    {
        $movie = Movie::factory()->create([
            'display_code' => 'SSIS-888',
            'code_normalized' => 'SSIS888',
        ]);
        Movie::factory()->create([
            'display_code' => 'ABP-888',
            'code_normalized' => 'ABP888',
        ]);

        $response = $this->getJson('/api/v1/movies?code=SSIS888', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.code', $movie->display_code);
    }

    public function test_actress_search_matches_romaji_kanji_and_alias_ac41(): void
    {
        $movie = Movie::factory()->create();
        $performer = Performer::factory()->create([
            'name_romaji' => 'Airi Suzumura',
            'name_kanji' => '涼村あいり',
        ]);
        PerformerAlias::create([
            'performer_id' => $performer->id,
            'alias' => 'AiriS',
            'kind' => 'stage',
        ]);
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $performer->id,
            'source_slug' => $performer->source_slug,
        ]);

        foreach (['Airi', '涼村', 'AiriS'] as $term) {
            $response = $this->getJson('/api/v1/movies?q=' . urlencode($term), [
                'X-API-Key' => $this->apiKey,
            ]);

            $response->assertOk()
                ->assertJsonPath('meta.pagination.total', 1)
                ->assertJsonPath('data.0.code', $movie->display_code);
        }
    }

    public function test_combined_filters_every_result_satisfies_all_filters_ac42(): void
    {
        $genre = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        $matching = Movie::factory()->count(5)->create([
            'release_date' => '2021-06-15',
            'runtime_minutes' => 90,
            'censored' => Movie::CENSORED_CENSORED,
        ]);
        foreach ($matching as $movie) {
            MovieGenre::factory()->create([
                'movie_id' => $movie->id,
                'genre_id' => $genre->id,
                'source_slug' => 'javdb',
            ]);
        }

        // Decoys: one filter violation each.
        Movie::factory()->create(['release_date' => '2019-01-01', 'runtime_minutes' => 90, 'censored' => Movie::CENSORED_CENSORED]);
        Movie::factory()->create(['release_date' => '2021-06-15', 'runtime_minutes' => 300, 'censored' => Movie::CENSORED_CENSORED]);
        Movie::factory()->create(['release_date' => '2021-06-15', 'runtime_minutes' => 90, 'censored' => Movie::CENSORED_UNCENSORED]);
        Movie::factory()->create(['release_date' => '2021-06-15', 'runtime_minutes' => 90, 'censored' => Movie::CENSORED_CENSORED]);

        $response = $this->getJson(
            '/api/v1/movies?genre=drama&released_from=2020-01-01&released_to=2022-12-31&runtime_min=60&runtime_max=120&censored=1',
            ['X-API-Key' => $this->apiKey],
        );

        $response->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.pagination.total', 5);

        $byCode = $matching->keyBy('display_code');

        foreach ($response->json('data') as $item) {
            $movie = $byCode[$item['code']];
            $this->assertTrue($movie->genres()->where('genres.id', $genre->id)->exists(), 'genre filter violated');
            $this->assertGreaterThanOrEqual('2020-01-01', $movie->release_date->format('Y-m-d'), 'released_from violated');
            $this->assertLessThanOrEqual('2022-12-31', $movie->release_date->format('Y-m-d'), 'released_to violated');
            $this->assertGreaterThanOrEqual(60, $movie->runtime_minutes, 'runtime_min violated');
            $this->assertLessThanOrEqual(120, $movie->runtime_minutes, 'runtime_max violated');
            $this->assertSame(Movie::CENSORED_CENSORED, $movie->censored, 'censored filter violated');
        }
    }

    public function test_sort_by_release_date_desc_with_id_tie_break(): void
    {
        $first = Movie::factory()->create(['release_date' => '2021-01-01']);
        $second = Movie::factory()->create(['release_date' => '2023-01-01']);
        $third = Movie::factory()->create(['release_date' => '2023-01-01']);
        $fourth = Movie::factory()->create(['release_date' => '2020-01-01']);

        $response = $this->getJson('/api/v1/movies?sort=release_date', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()->assertJsonPath('data.0.code', $third->display_code)
            ->assertJsonPath('data.1.code', $second->display_code)
            ->assertJsonPath('data.2.code', $first->display_code)
            ->assertJsonPath('data.3.code', $fourth->display_code);
    }

    public function test_sort_by_rating_desc_nulls_last(): void
    {
        Movie::factory()->create(['community_score' => 2.1]);
        $top = Movie::factory()->create(['community_score' => 9.9]);
        $mid = Movie::factory()->create(['community_score' => 8.4]);
        $unknown = Movie::factory()->create(['community_score' => null]);

        $response = $this->getJson('/api/v1/movies?sort=rating', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.code', $top->display_code)
            ->assertJsonPath('data.1.code', $mid->display_code)
            ->assertJsonPath('data.3.code', $unknown->display_code);
    }

    public function test_sort_by_update_date_desc(): void
    {
        $old = Movie::factory()->create(['updated_at' => now()->subDays(10)]);
        $new = Movie::factory()->create(['updated_at' => now()]);

        $response = $this->getJson('/api/v1/movies?sort=update_date', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.0.code', $new->display_code)
            ->assertJsonPath('data.1.code', $old->display_code);
    }

    public function test_cursor_pagination_no_duplicates_no_skips_ac43(): void
    {
        $movies = Movie::factory()->count(7)->create();
        // Same order as the keyset: release_date DESC, then id DESC for movies that share a date.
        $codes = $movies->sort(static fn($x, $y): int => [(string) $y->release_date, $y->id] <=> [(string) $x->release_date, $x->id])->pluck('display_code')->all();

        $pageCodes = [];
        $cursor = null;
        $pages = 0;

        do {
            $url = '/api/v1/movies?sort=release_date&per_page=3' . ($cursor !== null ? '&cursor=' . urlencode($cursor) : '');
            $response = $this->getJson($url, ['X-API-Key' => $this->apiKey]);
            $response->assertOk();

            $pageCodes = array_merge($pageCodes, collect($response->json('data'))->pluck('code')->all());
            $cursor = $response->json('meta.pagination.next_cursor');
            $pages++;
            // next_cursor is also emitted on the final page (AC-4.3 page-beyond);
            // stop on has_more=false so we do not count the empty follow-up page.
        } while ($response->json('meta.pagination.has_more') === true);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $pageCodes);
        $this->assertSame($codes, $pageCodes, 'keyset pages must not duplicate or skip rows');
    }

    public function test_cursor_pagination_survives_concurrent_insert_ac44(): void
    {
        foreach (['2025-01-01', '2024-01-01', '2023-01-01', '2022-01-01', '2021-01-01'] as $date) {
            Movie::factory()->create(['release_date' => $date]);
        }
        $original = Movie::query()->orderBy('id')->get();

        $page1 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2', ['X-API-Key' => $this->apiKey]);
        $page1->assertOk();
        $page1Codes = collect($page1->json('data'))->pluck('code')->all();
        $cursor = $page1->json('meta.pagination.next_cursor');
        $this->assertNotNull($cursor);

        // Insert a movie that would sort FIRST if the query were re-run.
        $newTop = Movie::factory()->create(['release_date' => '2026-01-01']);

        $page2 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2&cursor=' . urlencode((string) $cursor), ['X-API-Key' => $this->apiKey]);
        $page2->assertOk();
        $page2Codes = collect($page2->json('data'))->pluck('code')->all();

        $this->assertSame([], array_intersect($page1Codes, $page2Codes), 'page 2 duplicated page 1 rows');
        $this->assertNotContains($newTop->display_code, $page2Codes, 'rows before the cursor must not leak into page 2');

        // Insert a movie that sorts LAST; it belongs to the remaining range.
        $newTail = Movie::factory()->create(['release_date' => '2018-01-01']);
        $cursor2 = $page2->json('meta.pagination.next_cursor');

        $page3 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2&cursor=' . urlencode((string) $cursor2), ['X-API-Key' => $this->apiKey]);
        $page3->assertOk()->assertJsonPath('meta.pagination.has_more', false);
        $page3Codes = collect($page3->json('data'))->pluck('code')->all();

        $all = array_merge($page1Codes, $page2Codes, $page3Codes);
        $this->assertCount(count(array_unique($all)), $all, 'no duplicates across pages');

        $originalCodes = $original->pluck('display_code')->all();
        foreach ($originalCodes as $code) {
            $this->assertContains($code, $all, 'an original row was skipped by the concurrent insert');
        }
        $this->assertContains($newTail->display_code, $all);
        $this->assertNotContains($newTop->display_code, $all);
    }

    public function test_cursor_pagination_with_null_release_dates(): void
    {
        $dated1 = Movie::factory()->create(['release_date' => '2023-01-01']);
        $dated2 = Movie::factory()->create(['release_date' => '2022-01-01']);
        $undated = Movie::factory()->create(['release_date' => null]);

        $page1 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2', ['X-API-Key' => $this->apiKey]);
        $page1->assertOk();
        $cursor = $page1->json('meta.pagination.next_cursor');

        $page2 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2&cursor=' . urlencode((string) $cursor), ['X-API-Key' => $this->apiKey]);
        $page2->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $undated->display_code)
            ->assertJsonPath('meta.pagination.has_more', false);

        $all = collect($page1->json('data'))->pluck('code')->merge(collect($page2->json('data'))->pluck('code'))->all();
        $this->assertCount(3, $all);
        $this->assertSame([$dated1->display_code, $dated2->display_code, $undated->display_code], $all);
    }

    public function test_sort_stable_across_identical_calls_ac44(): void
    {
        Movie::factory()->count(4)->create();

        $first = $this->getJson('/api/v1/movies?sort=release_date&per_page=3', ['X-API-Key' => $this->apiKey]);
        $second = $this->getJson('/api/v1/movies?sort=release_date&per_page=3', ['X-API-Key' => $this->apiKey]);

        $this->assertSame(
            collect($first->json('data'))->pluck('code')->all(),
            collect($second->json('data'))->pluck('code')->all(),
        );
    }

    public function test_search_without_params_returns_all_movies_sorted_by_release_date(): void
    {
        $movies = Movie::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/movies', ['X-API-Key' => $this->apiKey]);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.per_page', 10)
            ->assertJsonPath('meta.pagination.sort', 'release_date');

        $codes = collect($response->json('data'))->pluck('code')->all();
        $expected = $movies->sort(static fn($x, $y): int => [(string) $y->release_date, $y->id] <=> [(string) $x->release_date, $x->id])->pluck('display_code')->all();
        $this->assertSame($expected, $codes);
    }

    public function test_q_empty_one_char_or_symbols_only_returns_invalid_filter_ac45(): void
    {
        foreach (['', 'a', urlencode('!!')] as $q) {
            $response = $this->getJson('/api/v1/movies?q=' . $q, ['X-API-Key' => $this->apiKey]);

            $response->assertBadRequest()
                ->assertJsonPath('type', 'invalid_filter');
        }
    }

    public function test_released_to_before_released_from_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/movies?released_from=2022-01-01&released_to=2021-01-01', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_runtime_max_before_runtime_min_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/movies?runtime_min=120&runtime_max=60', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_invalid_sort_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/movies?sort=bogus', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_page_beyond_total_returns_empty_data_and_same_total(): void
    {
        Movie::factory()->count(3)->create();

        $page1 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2', ['X-API-Key' => $this->apiKey]);
        $page1->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.has_more', true);

        $page2 = $this->getJson(
            '/api/v1/movies?sort=release_date&per_page=2&cursor=' . urlencode((string) $page1->json('meta.pagination.next_cursor')),
            ['X-API-Key' => $this->apiKey],
        );
        $page2->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.has_more', false);

        $page3 = $this->getJson(
            '/api/v1/movies?sort=release_date&per_page=2&cursor=' . urlencode((string) $page2->json('meta.pagination.next_cursor')),
            ['X-API-Key' => $this->apiKey],
        );
        $page3->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.has_more', false)
            ->assertJsonPath('meta.pagination.next_cursor', null);
    }

    public function test_per_page_above_100_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/movies?per_page=101', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_invalid_cursor_returns_invalid_filter(): void
    {
        $response = $this->getJson('/api/v1/movies?cursor=garbage', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_cursor_with_changed_sort_returns_invalid_filter(): void
    {
        Movie::factory()->count(3)->create();

        $page1 = $this->getJson('/api/v1/movies?sort=release_date&per_page=2', ['X-API-Key' => $this->apiKey]);

        $response = $this->getJson(
            '/api/v1/movies?sort=rating&per_page=2&cursor=' . urlencode((string) $page1->json('meta.pagination.next_cursor')),
            ['X-API-Key' => $this->apiKey],
        );

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_search_never_uses_offset(): void
    {
        Movie::factory()->count(5)->create();

        DB::enableQueryLog();
        $this->getJson('/api/v1/movies?q=test&sort=release_date&per_page=2', ['X-API-Key' => $this->apiKey])->assertOk();

        foreach (DB::getQueryLog() as $log) {
            $this->assertStringNotContainsStringIgnoringCase('offset', (string) $log['query']);
        }
    }

    // The CI stability job owns elapsed-time budgets; this test checks result correctness at scale.
    public function test_search_returns_expected_results_at_5k_scale(): void
    {
        $movies = Movie::factory()->count(self::SEED_COUNT)->create();
        $target = $movies->first();
        $target->forceFill(['title_en' => 'NebulaZero Protocol'])->save();
        $genre = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        MovieGenre::factory()->create([
            'movie_id' => $target->id,
            'genre_id' => $genre->id,
            'source_slug' => 'javdb',
        ]);

        $filtered = $this->getJson('/api/v1/movies?q=nebulazero&genre=drama&sort=release_date&per_page=5', ['X-API-Key' => $this->apiKey]);

        $filtered->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $target->display_code);

        $broad = $this->getJson('/api/v1/movies?sort=release_date&per_page=20', ['X-API-Key' => $this->apiKey]);

        $broad->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.pagination.total', self::SEED_COUNT);
    }
}
