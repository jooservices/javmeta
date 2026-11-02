<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Crawl\CrawlStatsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

final class StatsRollupCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rollup_upserts_hourly_rows_idempotently_and_report_groups_by_method(): void
    {
        $this->freezeTime();
        $hour = now()->utc()->startOfHour();
        config()->set('javmeta_stats.enabled', true);
        config()->set('javmeta_stats.retention_days', 3);
        config()->set('openobserve.enabled', true);
        config()->set('openobserve.url', 'https://metrics.example.test');
        config()->set('openobserve.org', 'default');
        Http::fake();
        $this->insertAttempt(now()->utc()->subMinutes(2), 'ok', 200, 120, 'http');
        $this->insertAttempt(now()->utc()->subMinute(), 'error', 403, 80, 'http');
        DB::table('crawl_stats_hourly')->insert([
            'hour_at' => now()->utc()->subDays(4)->startOfHour(),
            'source_slug' => 'old-source',
            'lane' => 'normal',
            'method' => 'http',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('crawl_runs')->insert([
            'source_slug' => 'onejav',
            'started_at' => now()->utc()->subMinutes(2),
            'finished_at' => now()->utc()->subMinute(),
            'status' => 'completed',
            'movies_new' => 2,
            'movies_updated' => 3,
            'proxy_bytes' => 1024,
        ]);

        $firstRun = hrtime(true);
        $this->artisan('crawler:stats-rollup --since=' . $hour->toIso8601String())->assertSuccessful();
        $elapsedMs = (hrtime(true) - $firstRun) / 1_000_000;
        $this->artisan('crawler:stats-rollup --since=' . $hour->toIso8601String())->assertSuccessful();

        $this->assertDatabaseCount('crawl_stats_hourly', 2);
        $this->assertDatabaseHas('crawl_stats_hourly', [
            'hour_at' => $hour->toDateTimeString(),
            'source_slug' => 'onejav',
            'lane' => 'normal',
            'method' => 'http',
            'attempts' => 2,
            'ok' => 1,
            'blocked' => 1,
            'avg_duration_ms' => 100,
            'p90_duration_ms' => 120,
        ]);
        $this->assertDatabaseHas('crawl_stats_hourly', [
            'hour_at' => $hour->toDateTimeString(),
            'source_slug' => CrawlStatsRollup::GLOBAL_SOURCE,
            'movies_new' => 2,
            'movies_updated' => 3,
            'bytes_total' => 1024,
        ]);
        $this->assertDatabaseMissing('crawl_stats_hourly', ['source_slug' => 'old-source']);
        Http::assertSent(static function ($request): bool {
            $metrics = $request->data()['resourceMetrics'][0]['scopeMetrics'][0]['metrics'] ?? [];
            $names = array_column($metrics, 'name');

            return in_array('javmeta_stats_movies_per_hour', $names, true)
                && in_array('javmeta_stats_source_success_ratio', $names, true);
        });
        self::assertLessThan(1000, $elapsedMs, 'SQLite test rollup should finish in under one second.');

        $this->assertReportGroup('method', 'http', $hour);
        $this->assertReportGroup('source', 'onejav', $hour);
        $this->assertReportGroup('lane', 'normal', $hour);
        $this->assertReportGroup('hour', $hour->format('Y-m-d H:00'), $hour);
        $this->artisan('crawler:stats-report --by=invalid')->assertFailed();
        $this->artisan('crawler:stats-rollup --since=not-a-date')->assertFailed();
    }

    public function test_disabled_setting_skips_rollup(): void
    {
        config()->set('javmeta_stats.enabled', false);

        $this->artisan('crawler:stats-rollup')->assertSuccessful();

        $this->assertDatabaseCount('crawl_stats_hourly', 0);
    }

    public function test_empty_scheduled_rollup_stores_global_hours_but_backfill_needs_attempt_data(): void
    {
        $this->freezeTime();
        config()->set('javmeta_stats.enabled', true);

        $this->artisan('crawler:stats-rollup')->assertSuccessful();
        $this->assertDatabaseCount('crawl_stats_hourly', 3);
        $this->assertDatabaseHas('crawl_stats_hourly', [
            'source_slug' => CrawlStatsRollup::GLOBAL_SOURCE,
            'attempts' => 0,
            'movies_new' => 0,
        ]);

        $this->artisan('crawler:stats-rollup --since=2026-10-01T00:00:00Z')->assertSuccessful();

        $this->assertDatabaseCount('crawl_stats_hourly', 3);
    }

    public function test_scheduled_rollup_keeps_earlier_global_hours_when_the_current_hour_has_attempts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        config()->set('javmeta_stats.enabled', true);
        $now = now('UTC')->toImmutable();
        $currentHour = $now->startOfHour();
        $previousHour = $currentHour->subHour();
        $this->insertAttempt($now->subMinutes(2), 'ok', 200, 120, 'http');
        DB::table('crawl_runs')->insert([
            'source_slug' => 'onejav',
            'started_at' => $previousHour->addMinutes(10),
            'finished_at' => $previousHour->addMinutes(20),
            'status' => 'completed',
            'movies_new' => 7,
            'movies_updated' => 2,
        ]);

        $this->artisan('crawler:stats-rollup')->assertSuccessful();

        $this->assertDatabaseHas('crawl_stats_hourly', [
            'hour_at' => $previousHour->toDateTimeString(),
            'source_slug' => CrawlStatsRollup::GLOBAL_SOURCE,
            'movies_new' => 7,
            'movies_updated' => 2,
        ]);
    }

    public function test_hourly_schedule_executes_the_registered_rollup_command_through_artisan_kernel(): void
    {
        $this->freezeTime();
        config()->set('javmeta_stats.enabled', true);
        $previousEnvironment = app('env');
        app()->instance('env', 'local');
        $this->app->instance(Schedule::class, new Schedule());
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        try {
            require base_path('routes/console.php');
            $event = collect(app(Schedule::class)->events())
                ->first(static fn($event): bool => str_contains((string) $event->command, 'crawler:stats-rollup'));
            self::assertNotNull($event);
            $matchedCommand = preg_match('/artisan[\'\"]?\s+[\'\"]?([^\s\'\"]+)/', (string) $event->command, $matches);
            self::assertSame(1, $matchedCommand);
            self::assertSame('crawler:stats-rollup', $matches[1]);

            self::assertSame(0, app(Kernel::class)->call($matches[1]));
            $this->assertDatabaseCount('crawl_stats_hourly', 3);
        } finally {
            app()->instance('env', $previousEnvironment);
            ScheduleFacade::clearResolvedInstance(Schedule::class);
        }
    }

    private function assertReportGroup(string $by, string $group, \DateTimeInterface $hour): void
    {
        $this->artisan("crawler:stats-report --hours=2 --source=onejav --by={$by}")
            ->expectsTable(
                ['Group', 'Attempts', 'OK', 'Success', 'Blocked', 'Timeout', 'Other error', 'Skipped', 'Avg ms', 'Max slice p90 ms', 'Bytes'],
                [[$group, 2, 1, '50.0%', 1, 0, 0, 0, '100.0', 120, '—']],
            )
            ->expectsTable(
                ['Hour (UTC)', 'New movies', 'Updated', 'New performers', 'Failed jobs', 'Ready avg', 'Delayed avg', 'Reserved avg', 'Playwright busy', 'FlareSolverr busy', 'PG connections avg'],
                [[$hour->format('Y-m-d H:00'), 2, 3, 0, 0, '—', '—', '—', '—', '—', '—']],
            )
            ->assertSuccessful();
    }

    private function insertAttempt(\DateTimeInterface $startedAt, string $outcome, ?int $status, int $duration, string $method): void
    {
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => $startedAt,
            'source_slug' => 'onejav',
            'lane' => 'normal',
            'url_hash' => hash('sha256', $method . $duration),
            'method' => $method,
            'started_at' => $startedAt,
            'duration_ms' => $duration,
            'http_status' => $status,
            'outcome' => $outcome,
            'error_code' => $outcome === 'error' ? 'http_error' : null,
        ]);
    }
}
