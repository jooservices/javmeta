<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/** The statistics screens on real PostgreSQL. */
final class DiscoveryStatisticsTest extends DiscoveryTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        // The statistics are for signed-in users.
        $this->actingAs(User::factory()->create(), 'web');
    }

    public function test_the_overview_counts_the_catalogue_and_names_when_the_data_was_built(): void
    {
        $response = $this->getJson('/api/v2/statistics/overview')->assertOk();

        $response->assertJsonPath('data.movies', 3)->assertJsonPath('data.performers', 3)->assertJsonPath('data.performers_with_photo', 1)->assertJsonPath('data.movies_with_cover', 2);
        $response->assertJsonPath('data.movies_with_download', 1)->assertJsonPath('data.gallery_photos', 4)->assertJsonPath('data.magnets', 2)->assertJsonPath('data.movies_24h', 1)->assertJsonPath('data.movies_1h', 0)->assertJsonPath('data.performers_24h', 1);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $response->json('data.data_as_of'));
        self::assertSame([1, 2, 3], array_column($response->json('data.quality_tiers'), 'tier'));
    }

    public function test_the_series_counts_new_rows_per_period_and_falls_back_to_safe_values(): void
    {
        $days = $this->getJson('/api/v2/statistics/series?metric=movies&interval=day&days=7')->assertOk();
        $days->assertJsonPath('meta.metric', 'movies')->assertJsonPath('meta.interval', 'day');
        self::assertSame(2, array_sum(array_column($days->json('data'), 'count')));

        $hours = $this->getJson('/api/v2/statistics/series?metric=performers&interval=hour&days=1')->assertOk();
        $hours->assertJsonPath('meta.metric', 'performers')->assertJsonPath('meta.interval', 'hour');
        self::assertSame(1, array_sum(array_column($hours->json('data'), 'count')));

        $this->getJson('/api/v2/statistics/series?metric=nope&interval=century&days=99999')->assertOk()->assertJsonPath('meta.metric', 'movies')->assertJsonPath('meta.interval', 'day');
        $this->getJson('/api/v2/statistics/series?interval=month')->assertOk()->assertJsonPath('meta.interval', 'month');
    }

    public function test_every_distribution_answers_label_and_count(): void
    {
        foreach (['year', 'genre', 'maker', 'cup', 'blood-type', 'age', 'height', 'runtime', 'quality'] as $by) {
            $response = $this->getJson('/api/v2/statistics/distributions?by=' . $by . '&limit=5')->assertOk()->assertJsonPath('meta.by', $by);
            self::assertNotEmpty($response->json('data'), $by);
            self::assertSame(['label', 'count'], array_keys($response->json('data.0')), $by);
        }

        $genres = $this->getJson('/api/v2/statistics/distributions?by=genre')->assertOk();
        self::assertSame(['label' => 'Drama', 'count' => 2], $genres->json('data.0'));
    }

    public function test_an_unknown_distribution_is_a_bad_request(): void
    {
        $this->getJson('/api/v2/statistics/distributions?by=colour')->assertStatus(400)->assertJsonPath('code', 'invalid_distribution');
        $this->getJson('/api/v2/statistics/distributions')->assertStatus(400)->assertJsonPath('code', 'invalid_distribution');
    }

    public function test_every_correlation_answers_x_y_and_n(): void
    {
        $this->performer(4, 'Tall Cup', ['birth_date' => '1995-01-01', 'height_cm' => 170, 'cup' => 'C', 'movie_count' => 5]);

        foreach (['age-movies', 'height-movies', 'year-score', 'year-runtime', 'cup-height'] as $pair) {
            $response = $this->getJson('/api/v2/statistics/correlations?pair=' . $pair)->assertOk()->assertJsonPath('meta.pair', $pair);
            self::assertNotEmpty($response->json('data'), $pair);
            self::assertSame(['x', 'y', 'n'], array_keys($response->json('data.0')), $pair);
        }

        $this->getJson('/api/v2/statistics/correlations')->assertOk()->assertJsonPath('meta.pair', 'age-movies');
        $this->getJson('/api/v2/statistics/correlations?pair=cup-height')->assertJsonPath('data.0.x', 'B');
        $this->getJson('/api/v2/statistics/correlations?pair=year-score')->assertJsonPath('data.0.x', 2025);
    }

    public function test_an_unknown_correlation_is_a_bad_request(): void
    {
        $this->getJson('/api/v2/statistics/correlations?pair=age-height')->assertStatus(400)->assertJsonPath('code', 'invalid_pair');
    }

    public function test_data_quality_counts_how_many_performers_carry_each_attribute(): void
    {
        $this->getJson('/api/v2/statistics/data-quality')->assertOk()->assertJsonPath('data.total', 3)->assertJsonPath('data.birth', 1)->assertJsonPath('data.cup', 2)->assertJsonPath('data.photo', 1)->assertJsonPath('data.bio', 0);
    }

    public function test_crawl_health_sums_the_hourly_figures_without_the_global_row(): void
    {
        $response = $this->getJson('/api/v2/statistics/crawl-health?hours=24')->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.ok', 8)->assertJsonPath('data.0.attempts', 10)->assertJsonPath('data.0.blocked', 1)->assertJsonPath('data.0.timeout', 1)->assertJsonPath('data.0.errors', 0);
        self::assertMatchesRegularExpression('/T\d{2}:00:00Z$/', $response->json('data.0.hour_at'));
        $this->getJson('/api/v2/statistics/crawl-health')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_contributions_name_the_sources_that_brought_new_movies_today(): void
    {
        $this->getJson('/api/v2/statistics/contributions')->assertOk()->assertExactJson(['data' => [['slug' => 'alpha-src', 'name' => 'Alpha Source', 'n' => 1]], 'meta' => []]);
    }

    public function test_contributions_fall_back_to_the_slug_of_an_unknown_source(): void
    {
        $this->movie(20, 'ZZZ-020', 'Odd', ['first_seen_at' => now('UTC'), 'sources' => '{ghost}']);

        $names = array_column($this->getJson('/api/v2/statistics/contributions')->assertOk()->json('data'), 'name', 'slug');
        self::assertSame('ghost', $names['ghost']);
    }

    public function test_sources_active_counts_enabled_and_all_sources(): void
    {
        $this->getJson('/api/v2/statistics/sources-active')->assertOk()->assertExactJson(['data' => ['active' => 1, 'total' => 2], 'meta' => []]);
    }

    public function test_the_dashboard_has_every_block_the_statistics_page_draws(): void
    {
        $response = $this->getJson('/api/v2/statistics/dashboard')->assertOk();

        $response->assertJsonPath('data.totals.movies', 3)->assertJsonPath('data.with_maker', 2)->assertJsonPath('data.coverage.total', 3)->assertJsonPath('data.makers.0.n', 1);
        foreach (['movies_series', 'performers_series', 'years', 'makers', 'series', 'labels', 'genres', 'runtime', 'score', 'months', 'cast', 'tiers', 'coverage', 'totals'] as $block) {
            $response->assertJsonStructure(['data' => [$block]]);
        }
    }

    public function test_compare_scores_two_or_three_entries_on_seven_axes(): void
    {
        $response = $this->getJson('/api/v2/statistics/compare?kind=makers&names=Maker%20One,Maker%20Two,Nobody,Extra')->assertOk();

        self::assertCount(2, $response->json('data'));
        $one = collect($response->json('data'))->firstWhere('name', 'Maker One');
        self::assertSame(['scale' => 100, 'tier3' => 100, 'score' => 85, 'links' => 100, 'watch' => 100, 'cast' => 100, 'recent' => 0], $one['axes']);
        self::assertSame(1, $one['n']);
        self::assertEqualsWithDelta(1 / 3, $one['share'], 0.0001);
    }

    public function test_compare_works_on_genres_and_scores_a_missing_score_as_zero(): void
    {
        $response = $this->getJson('/api/v2/statistics/compare?kind=genres&names=Drama,Comedy')->assertOk();

        self::assertSame(['Drama', 'Comedy'], array_column($response->json('data'), 'name'));
        $this->getJson('/api/v2/statistics/compare?kind=labels&names=Label%20One,Nothing')->assertOk()->assertJsonCount(1, 'data');
        $this->movie(30, 'NOS-030', 'No score', ['maker' => 'Maker Three']);
        $this->movie(31, 'NOS-031', 'No score either', ['maker' => 'Maker Four']);
        self::assertSame(0, $this->getJson('/api/v2/statistics/compare?kind=makers&names=Maker%20Three,Maker%20Four')->json('data.0.axes.score'));
    }

    public function test_compare_needs_a_known_kind_and_two_names(): void
    {
        $this->getJson('/api/v2/statistics/compare?kind=colours&names=a,b')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
        $this->getJson('/api/v2/statistics/compare?kind=makers&names=only')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
        $this->getJson('/api/v2/statistics/compare')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
    }
}
