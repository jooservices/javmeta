<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use App\Models\User;
use Tests\Support\Api\V2\FakesPostgresRows;

/** The statistics screens with canned PostgreSQL rows: shaping, clamping, validation and the short cache. */
final class StatisticsServiceTest extends V2TestCase
{
    use FakesPostgresRows;

    protected function setUp(): void
    {
        parent::setUp();

        // The statistics are for signed-in users: act as one without storing it (the database is faked in these tests).
        $this->actingAs((new User())->forceFill(['id' => 1, 'role' => 'user']), 'web');
    }

    public function test_the_overview_adds_the_quality_tiers_and_formats_the_refresh_time_in_utc(): void
    {
        $this->fakeRows([
            'AS data_as_of' => [['movies' => 10, 'performers' => 4, 'data_as_of' => '2024-05-02 12:00:00+02']],
            'SELECT tier, count(*)::int AS count FROM rm_movies' => [['tier' => 3, 'count' => '6'], ['tier' => null, 'count' => '4']],
        ]);

        $this->getJson('/api/v2/statistics/overview')
            ->assertOk()
            ->assertJsonPath('data.movies', 10)
            ->assertJsonPath('data.data_as_of', '2024-05-02T10:00:00Z')
            ->assertJsonPath('data.quality_tiers', [['tier' => 3, 'count' => 6], ['tier' => null, 'count' => 4]]);
    }

    public function test_the_overview_without_a_refresh_time_has_a_null_time_and_is_served_from_the_cache_the_second_time(): void
    {
        $this->fakeRows([
            'AS data_as_of' => [['movies' => 0, 'data_as_of' => null]],
            'SELECT tier, count(*)::int AS count FROM rm_movies' => [],
        ]);

        $this->getJson('/api/v2/statistics/overview')->assertOk()->assertJsonPath('data.data_as_of', null)->assertJsonPath('data.quality_tiers', []);
        $this->getJson('/api/v2/statistics/overview')->assertOk();

        $this->assertCount(1, $this->sqlContaining('AS data_as_of'));
    }

    public function test_a_series_is_clamped_and_falls_back_to_movies_per_day(): void
    {
        $this->fakeRows(['date_trunc(' => [['period' => '2024-05-01T00:00', 'count' => '5']]]);

        $this->getJson('/api/v2/statistics/series')
            ->assertOk()
            ->assertJsonPath('data', [['period' => '2024-05-01T00:00', 'count' => 5]])
            ->assertJsonPath('meta', ['metric' => 'movies', 'interval' => 'day']);
        $this->assertStringContainsString("date_trunc('day'", $this->sqlCalls[0]['sql']);
        $this->assertStringContainsString('FROM rm_movies ', $this->sqlCalls[0]['sql']);
        $this->assertSame([30], $this->sqlCalls[0]['bindings']);
    }

    public function test_a_series_of_performers_by_hour_or_month_and_the_days_are_limited_to_two_years(): void
    {
        $this->fakeRows(['date_trunc(' => []]);

        $this->getJson('/api/v2/statistics/series?metric=performers&interval=hour&days=9999')->assertOk()->assertJsonPath('meta', ['metric' => 'performers', 'interval' => 'hour']);
        $this->getJson('/api/v2/statistics/series?metric=bogus&interval=month&days=-5')->assertOk()->assertJsonPath('meta', ['metric' => 'movies', 'interval' => 'month']);
        $this->getJson('/api/v2/statistics/series?interval=century')->assertOk()->assertJsonPath('meta.interval', 'day');

        $this->assertStringContainsString("date_trunc('hour'", $this->sqlCalls[0]['sql']);
        $this->assertStringContainsString('FROM rm_performers ', $this->sqlCalls[0]['sql']);
        $this->assertSame([730], $this->sqlCalls[0]['bindings']);
        $this->assertSame([1], $this->sqlCalls[1]['bindings']);
    }

    public function test_a_distribution_passes_the_clamped_limit_only_to_the_queries_that_have_one(): void
    {
        $this->fakeRows([
            'SELECT g AS label' => [['label' => 'Drama', 'count' => '9']],
            'SELECT cup AS label' => [['label' => 'D', 'count' => 2]],
        ]);

        $this->getJson('/api/v2/statistics/distributions?by=genre&limit=500')->assertOk()->assertJsonPath('data', [['label' => 'Drama', 'count' => 9]])->assertJsonPath('meta.by', 'genre');
        $this->getJson('/api/v2/statistics/distributions?by=cup')->assertOk()->assertJsonPath('data.0.label', 'D');

        $this->assertSame([60], $this->sqlCalls[0]['bindings']);
        $this->assertSame([], $this->sqlCalls[1]['bindings']);
    }

    public function test_every_distribution_name_is_accepted_and_others_are_a_400_listing_the_choices(): void
    {
        $this->fakeRows(['' => []]);

        foreach (['year', 'genre', 'maker', 'cup', 'blood-type', 'age', 'height', 'runtime', 'quality'] as $by) {
            $this->getJson('/api/v2/statistics/distributions?by=' . $by)->assertOk();
        }

        $this->getJson('/api/v2/statistics/distributions')->assertStatus(400)->assertJsonPath('code', 'invalid_distribution');
        $this->getJson('/api/v2/statistics/distributions?by=colour')->assertStatus(400)->assertJsonPath('detail', 'by must be year, genre, maker, cup, blood-type, age, height, runtime, quality');
    }

    public function test_correlation_points_use_numbers_for_numeric_x_and_text_otherwise(): void
    {
        $this->fakeRows([
            'AS x, round(avg(movie_count)' => [['x' => '25', 'y' => '11.5', 'n' => '30']],
            'SELECT cup AS x' => [['x' => 'D', 'y' => 160.5, 'n' => 4]],
        ]);

        $this->getJson('/api/v2/statistics/correlations')->assertOk()->assertJsonPath('data', [['x' => 25, 'y' => 11.5, 'n' => 30]])->assertJsonPath('meta.pair', 'age-movies');
        $this->getJson('/api/v2/statistics/correlations?pair=cup-height')->assertOk()->assertJsonPath('data', [['x' => 'D', 'y' => 160.5, 'n' => 4]]);
    }

    public function test_an_unknown_correlation_pair_is_a_400(): void
    {
        $this->fakeRows([]);

        $this->getJson('/api/v2/statistics/correlations?pair=a-b')->assertStatus(400)->assertJsonPath('code', 'invalid_pair');
    }

    public function test_data_quality_counts_are_integers(): void
    {
        $this->fakeRows(['count(birth_date)' => [['total' => '100', 'birth' => '40', 'photo' => 7]]]);

        $this->getJson('/api/v2/statistics/data-quality')->assertOk()->assertJsonPath('data', ['total' => 100, 'birth' => 40, 'photo' => 7]);
    }

    public function test_crawl_health_is_clamped_to_a_week_and_shaped_per_hour(): void
    {
        $this->fakeRows(['FROM crawl_stats_hourly' => [['hour_at' => '2024-05-02 10:00:00', 'ok' => '9', 'attempts' => '12', 'blocked' => '1', 'timeout' => '1', 'errors' => '1']]]);

        $this->getJson('/api/v2/statistics/crawl-health?hours=5000')
            ->assertOk()
            ->assertJsonPath('data', [['hour_at' => '2024-05-02T10:00:00Z', 'ok' => 9, 'attempts' => 12, 'blocked' => 1, 'timeout' => 1, 'errors' => 1]]);
        $this->getJson('/api/v2/statistics/crawl-health')->assertOk();
        $this->getJson('/api/v2/statistics/crawl-health?hours=-3')->assertOk();

        $this->assertSame([168], $this->sqlCalls[0]['bindings']);
        $this->assertSame([72], $this->sqlCalls[1]['bindings']);
        $this->assertSame([1], $this->sqlCalls[2]['bindings']);
    }

    public function test_contributions_and_active_sources(): void
    {
        $this->fakeRows([
            'unnest(m.sources) s' => [['slug' => 'javdb', 'name' => 'JavDB', 'n' => '12']],
            'FILTER (WHERE enabled)' => [['active' => '3', 'total' => '5']],
        ]);

        $this->getJson('/api/v2/statistics/contributions')->assertOk()->assertJsonPath('data', [['slug' => 'javdb', 'name' => 'JavDB', 'n' => 12]]);
        $this->getJson('/api/v2/statistics/sources-active')->assertOk()->assertJsonPath('data', ['active' => 3, 'total' => 5]);
    }

    public function test_the_dashboard_is_the_whole_statistics_page_in_one_payload(): void
    {
        $this->fakeRows([
            'AS movies, (SELECT' => [['movies' => 10, 'performers' => 4]],
            '' => [['n' => 3, 'd' => '2024-05-01', 'name' => 'x', 'k' => 'a', 'y' => 2020, 'tier' => 1, 'total' => 10]],
        ]);

        $data = $this->getJson('/api/v2/statistics/dashboard')->assertOk()->json('data');

        $this->assertSame(['movies' => 10, 'performers' => 4], $data['totals']);
        $this->assertSame(3, $data['with_maker']);
        $this->assertSame([['n' => 3, 'd' => '2024-05-01', 'name' => 'x', 'k' => 'a', 'y' => 2020, 'tier' => 1, 'total' => 10]], $data['makers']);
        $this->assertEqualsCanonicalizing(['totals', 'movies_series', 'performers_series', 'years', 'makers', 'series', 'labels', 'genres', 'runtime', 'score', 'months', 'cast', 'tiers', 'coverage', 'with_maker'], array_keys($data));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function compareRows(): array
    {
        return [
            'avg(CASE WHEN m.tier = 3' => [
                ['name' => 'Moodyz', 'n' => 50, 'tier3' => 0.5, 'score' => 7.46, 'links' => 0.8, 'watch' => 0.25, 'cast_share' => 1.0, 'recent' => 0.1],
                ['name' => 'S1', 'n' => 20, 'tier3' => null, 'score' => null, 'links' => null, 'watch' => null, 'cast_share' => null, 'recent' => null],
            ],
            'coalesce(max(n), 0)' => [['mx' => 100]],
            'SELECT count(*)::int AS total FROM rm_movies' => [['total' => 400]],
        ];
    }

    public function test_a_comparison_scales_seven_axes_from_zero_to_a_hundred(): void
    {
        $this->fakeRows($this->compareRows());

        $rows = $this->getJson('/api/v2/statistics/compare?kind=makers&names=Moodyz,S1')->assertOk()->json('data');

        $this->assertSame('Moodyz', $rows[0]['name']);
        $this->assertSame(50, $rows[0]['n']);
        $this->assertSame(0.125, $rows[0]['share']);
        $this->assertSame(['scale' => 71, 'tier3' => 50, 'score' => 75, 'links' => 80, 'watch' => 25, 'cast' => 100, 'recent' => 10], $rows[0]['axes']);
        $this->assertSame(['scale' => 45, 'tier3' => 0, 'score' => 0, 'links' => 0, 'watch' => 0, 'cast' => 0, 'recent' => 0], $rows[1]['axes']);
        $this->assertStringContainsString('m.maker', $this->sqlContaining('avg(CASE WHEN m.tier = 3')[0]['sql']);
    }

    public function test_a_comparison_keeps_three_names_and_ignores_empty_ones(): void
    {
        $this->fakeRows($this->compareRows());

        $this->getJson('/api/v2/statistics/compare?kind=genres&names=a,,b,c,d')->assertOk();

        $this->assertSame('{"a","b","c"}', $this->sqlContaining('avg(CASE WHEN m.tier = 3')[0]['bindings'][0]);
        $this->assertStringContainsString('unnest(m.genres) e', $this->sqlContaining('avg(CASE WHEN m.tier = 3')[0]['sql']);
    }

    public function test_a_comparison_of_an_empty_catalogue_never_divides_by_zero(): void
    {
        $this->fakeRows([
            'avg(CASE WHEN m.tier = 3' => [['name' => 'Moodyz', 'n' => 0, 'tier3' => 0.0, 'score' => 0.0, 'links' => 0.0, 'watch' => 0.0, 'cast_share' => 0.0, 'recent' => 0.0]],
            'coalesce(max(n), 0)' => [['mx' => 0]],
            'SELECT count(*)::int AS total FROM rm_movies' => [['total' => 0]],
        ]);

        $this->getJson('/api/v2/statistics/compare?kind=series&names=a,b')->assertOk()->assertJsonPath('data.0.share', 0)->assertJsonPath('data.0.axes.scale', 0);
    }

    public function test_a_comparison_needs_a_known_kind_and_two_names(): void
    {
        $this->fakeRows($this->compareRows());

        $this->getJson('/api/v2/statistics/compare?kind=makers&names=one')->assertStatus(400)->assertJsonPath('code', 'invalid_compare');
        $this->getJson('/api/v2/statistics/compare?kind=makers&names=,,')->assertStatus(400);
        $this->getJson('/api/v2/statistics/compare?kind=colours&names=a,b')->assertStatus(400);
        $this->getJson('/api/v2/statistics/compare')->assertStatus(400);

        foreach (['makers', 'series', 'labels', 'genres'] as $kind) {
            $this->getJson("/api/v2/statistics/compare?kind={$kind}&names=a,b")->assertOk();
        }
    }
}
