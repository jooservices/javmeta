<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieObservation;
use App\Models\Performer;
use App\Models\Source;
use App\Support\Code\NormalizedCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_quality_report_counts_filled_fields_tiers_conflicts_and_sources(): void
    {
        $full = $this->movie('AAA-0001', ['title_jp' => 'JP', 'title_en' => 'EN', 'cover_url' => 'https://x.test/c.jpg', 'release_date' => '2026-01-01', 'runtime_minutes' => 120, 'maker' => 'M', 'label' => 'L', 'series' => 'S', 'completeness_tier' => 3]);
        $full->performers()->attach(Performer::factory()->create()->id, ['source_slug' => 'demo', 'crawled_at' => now()]);
        $full->genres()->attach(Genre::factory()->create()->id, ['source_slug' => 'demo', 'crawled_at' => now()]);
        $this->observe($full, 'maker', 'one', 'demo');
        $this->observe($full, 'maker', 'two', 'other');
        $empty = $this->movie('BBB-0002', ['title_jp' => null, 'title_en' => null, 'cover_url' => null, 'release_date' => null, 'runtime_minutes' => null, 'maker' => null, 'label' => null, 'series' => null, 'completeness_tier' => 1]);
        $this->observe($empty, 'title_jp', 'a', 'demo');

        Artisan::call('javmeta:report', ['type' => 'quality']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(2, $report['movies']);
        self::assertEquals(['filled' => 1, 'percent' => 50.0], $report['core_fields']['maker']);
        self::assertEquals(['filled' => 2, 'percent' => 100.0], $report['core_fields']['code']);
        self::assertEquals(['count' => 1, 'percent' => 50.0], $report['movies_with_8_of_11']);
        self::assertSame(['1' => 1, '3' => 1], $report['completeness_tiers']);
        self::assertSame(1, $report['field_conflicts']['movies_with_a_conflict']);
        self::assertSame(['maker' => 1], $report['field_conflicts']['by_field']);
        self::assertSame(['demo' => 2, 'other' => 1], $report['movies_by_source']);
    }

    public function test_cost_report_projects_from_finished_runs_and_lists_failures_by_code(): void
    {
        DB::table('crawl_runs')->insert([
            'source_slug' => 'demo',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
            'status' => 'finished',
            'pages_fetched' => 50,
            'movies_new' => 90,
            'movies_updated' => 10,
            'failures' => 3,
            'proxy_requests' => 0,
            'proxy_bytes' => 0,
            'browser_fetches' => 0,
        ]);

        Artisan::call('javmeta:report', ['type' => 'cost']);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(100, $report['sources']['demo']['titles']);
        self::assertSame(50000, $report['sources']['demo']['projection']['100000']['pages']);
        self::assertEquals(1000.0, $report['sources']['demo']['projection']['100000']['hours']);
        self::assertEquals(4000.0, $report['sources']['demo']['projection']['400000']['hours']);
    }

    public function test_sources_report_shows_freshness_and_the_health_verdict(): void
    {
        Source::query()->create(['slug' => 'demo', 'name' => 'Demo', 'base_url' => 'https://demo.test', 'enabled' => true, 'priority' => 1, 'gap_seconds_default' => 10, 'gap_seconds_min' => 5, 'gap_seconds_max' => 45, 'gap_seconds_current' => 10, 'last_success_at' => now()->subMinutes(5)]);
        $directory = storage_path('app/private/reports');
        @mkdir($directory, 0775, true);
        file_put_contents($directory . '/source-health.json', json_encode(['rows' => [['source' => 'demo', 'verdict' => 'WORKING'], ['source' => 'demo', 'verdict' => 'BLOCKED']]]));

        try {
            Artisan::call('javmeta:report', ['type' => 'sources']);
            $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            @unlink($directory . '/source-health.json');
        }

        self::assertSame('BLOCKED', $report['sources']['demo']['health']);
        self::assertGreaterThanOrEqual(300, $report['sources']['demo']['last_success_age_seconds']);
    }

    public function test_unknown_report_type_fails_in_the_command_and_is_404_over_http(): void
    {
        self::assertSame(1, Artisan::call('javmeta:report', ['type' => 'nonsense']));

        $token = fake()->uuid();
        config(['javmeta_auth.owner_admin_token' => $token]);
        $this->getJson('/api/v1/admin/report/nonsense', ['X-Owner-Token' => $token])->assertNotFound();
    }

    public function test_admin_endpoint_needs_the_owner_token_and_answers_with_the_report(): void
    {
        $token = fake()->uuid();
        config(['javmeta_auth.owner_admin_token' => $token]);

        $this->getJson('/api/v1/admin/report/quality')->assertUnauthorized();
        $this->getJson('/api/v1/admin/report/quality', ['X-Owner-Token' => $token])
            ->assertOk()
            ->assertJsonPath('data.movies', 0);
    }

    public function test_verify_sample_lists_missing_codes_and_empty_core_fields(): void
    {
        $movie = $this->movie('CCC-0003', ['title_jp' => 'JP', 'maker' => null]);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => 'CCC-0003', 'code_normalized' => NormalizedCode::from('CCC-0003')->value()]);
        $file = tempnam(sys_get_temp_dir(), 'sample');
        file_put_contents($file, "# sample\nCCC-0003\nZZZ-9999\n\n");

        try {
            Artisan::call('javmeta:verify-sample', ['file' => $file]);
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            unlink($file);
        }

        self::assertSame(2, $result['codes']);
        self::assertSame(1, $result['found']);
        self::assertSame(['ZZZ-9999'], $result['missing']);
        self::assertContains('maker', $result['with_empty_core_fields']['CCC-0003']);
        self::assertSame(1, Artisan::call('javmeta:verify-sample', ['file' => '/nonexistent/file.txt']));
    }

    /** @param array<string, mixed> $attributes */
    private function movie(string $code, array $attributes): Movie
    {
        return Movie::factory()->create(['display_code' => $code, 'code_normalized' => str_replace('-', '', $code)] + $attributes);
    }

    private function observe(Movie $movie, string $field, string $value, string $source): void
    {
        MovieObservation::factory()->create([
            'movie_id' => $movie->id,
            'field' => $field,
            'value' => $value,
            'value_hash' => hash('sha256', $value),
            'source_slug' => $source,
        ]);
    }
}
