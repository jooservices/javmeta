<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Movie;
use App\Models\MovieObservation;
use App\Services\Reports\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class QualityReportMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_quality_report_conflict_section_matches_legacy_counts(): void
    {
        $movies = Movie::factory()->count(50)->create();
        foreach ($movies->take(5) as $movie) {
            $this->observe($movie, 'maker', 'first');
            $this->observe($movie, 'maker', 'second');
        }
        foreach ($movies->take(3) as $movie) {
            $this->observe($movie, 'title_en', 'first');
            $this->observe($movie, 'title_en', 'second');
        }

        $report = app(ReportService::class)->quality();

        self::assertSame(5, $report['field_conflicts']['movies_with_a_conflict']);
        self::assertSame(['maker' => 5, 'title_en' => 3], $report['field_conflicts']['by_field']);
    }

    /** @group memory */
    public function test_quality_report_does_not_hydrate_conflict_rows(): void
    {
        $this->seedConflicts(100_000);
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        app(ReportService::class)->quality();

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_conflict_summary_is_served_from_cache_on_second_quality_report(): void
    {
        $movie = Movie::factory()->create();
        $this->observe($movie, 'maker', 'first');
        $this->observe($movie, 'maker', 'second');
        $summaryQueries = 0;
        DB::listen(static function ($query) use (&$summaryQueries): void {
            if (str_contains(strtolower($query->sql), 'having count(distinct value_hash) > 1')) {
                $summaryQueries++;
            }
        });

        $reports = app(ReportService::class);
        $reports->quality();
        self::assertSame(2, $summaryQueries);
        $reports->quality();

        self::assertSame(2, $summaryQueries);
    }

    public function test_refresh_command_is_idempotent_and_scheduled_hourly_without_overlap(): void
    {
        $movie = Movie::factory()->create();
        $this->observe($movie, 'maker', 'first');
        $this->observe($movie, 'maker', 'second');
        Cache::spy();

        self::assertSame(0, Artisan::call('reports:field-conflicts'));
        self::assertSame(0, Artisan::call('reports:field-conflicts'));
        Cache::shouldHaveReceived('put')->twice();

        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(static fn($event): bool => str_contains((string) $event->command, 'reports:field-conflicts'));
        self::assertNotNull($event);
        self::assertSame('0 * * * *', $event->expression);
        self::assertTrue($event->withoutOverlapping);
    }

    /** @group memory */
    public function test_admin_report_quality_payload_stays_small_with_many_conflicts(): void
    {
        $this->seedConflicts(100_000);
        $token = fake()->uuid();
        config(['javmeta_auth.owner_admin_token' => $token]);

        $response = $this->getJson('/api/v1/admin/report/quality', ['X-Owner-Token' => $token])->assertOk();

        self::assertLessThanOrEqual(1024, strlen(json_encode($response->json('data.field_conflicts'), JSON_THROW_ON_ERROR)));
    }

    private function observe(Movie $movie, string $field, string $value): void
    {
        MovieObservation::create([
            'movie_id' => $movie->id,
            'field' => $field,
            'value' => $value,
            'value_hash' => hash('sha256', $value),
            'source_slug' => 'memory-test-' . $value,
            'crawled_at' => now(),
            'is_primary' => false,
        ]);
    }

    private function seedConflicts(int $pairs): void
    {
        $nextId = ((int) DB::table('movies')->max('id')) + 1;
        $timestamp = now()->toDateTimeString();
        for ($offset = 0; $offset < $pairs; $offset += 100) {
            $movieRows = [];
            $observationRows = [];
            $end = min($pairs, $offset + 100);
            for ($index = $offset; $index < $end; $index++) {
                $id = $nextId + $index;
                $suffix = str_pad((string) $id, 12, '0', STR_PAD_LEFT);
                $movieRows[] = [
                    'id' => $id,
                    'uuid' => '00000000-0000-4000-8000-' . $suffix,
                    'display_code' => 'MEM-' . $id,
                    'code_normalized' => 'MEM' . $id,
                    'completeness_tier' => 0,
                    'first_seen_at' => $timestamp,
                    'updated_at' => $timestamp,
                    'crawled_at' => $timestamp,
                ];
                foreach (['first', 'second'] as $value) {
                    $observationRows[] = [
                        'movie_id' => $id,
                        'field' => 'maker',
                        'value' => $value,
                        'value_hash' => hash('sha256', $value . '-' . $id),
                        'source_slug' => 'memory-' . $value,
                        'source_url' => null,
                        'crawled_at' => $timestamp,
                        'is_primary' => false,
                    ];
                }
            }
            DB::table('movies')->insert($movieRows);
            foreach (array_chunk($observationRows, 100) as $rows) {
                DB::table('movie_observations')->insert($rows);
            }
        }
    }
}
