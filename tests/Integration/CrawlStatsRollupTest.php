<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Crawl\CrawlStatsOperationalSampler;
use App\Services\Crawl\CrawlStatsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CrawlStatsRollupTest extends IntegrationTestCase
{
    public function test_postgres_rollup_is_idempotent_indexed_and_preserves_sampled_averages(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        config()->set('javmeta_stats.enabled', true);
        $now = now('UTC')->toImmutable();
        $hour = $now->startOfHour();
        $this->insertAttempt($now->copy()->subMinutes(2), 'ok', 200, 120);
        $this->insertAttempt($now->copy()->subMinute(), 'error', 403, 80);
        DB::table('crawl_runs')->insert([
            'source_slug' => 'onejav',
            'started_at' => $now->copy()->subMinutes(2),
            'finished_at' => $now->copy()->subMinute(),
            'status' => 'completed',
            'movies_new' => 2,
            'movies_updated' => 3,
            'proxy_bytes' => 1024,
        ]);
        $this->insertQueue('ready-a');
        $sampler = app(CrawlStatsOperationalSampler::class);
        $sampler->sample();
        $this->insertQueue('ready-b');
        $sampler->sample();

        $startedAt = hrtime(true);
        $rollup = app(CrawlStatsRollup::class)->rollup($hour, $now);
        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;
        app(CrawlStatsRollup::class)->rollup($hour, $now);

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
            'source_slug' => '__global__',
            'movies_new' => 2,
            'movies_updated' => 3,
            'bytes_total' => 1024,
            'queue_ready_avg' => 1.5,
            'queue_samples' => 2,
            'postgres_samples' => 2,
        ]);
        self::assertNotNull(DB::table('crawl_stats_hourly')->where('source_slug', '__global__')->value('postgres_connections_avg'));
        self::assertSame(1, $rollup['attempt_rows']);
        self::assertLessThan(1000, $elapsedMs, 'PostgreSQL rollup should finish in under one second.');

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'crawl_fetch_attempts')
            ->pluck('indexname')
            ->all();
        self::assertContains('crawl_fetch_attempts_started_at_idx', $indexes);
    }

    public function test_postgres_global_hour_groups_timezone_aware_timestamps_in_utc(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        $hour = now('UTC')->startOfHour();
        $now = now('UTC')->toImmutable();
        DB::statement("SET TIME ZONE 'Asia/Kolkata'");

        try {
            DB::table('crawl_runs')->insert([
                'source_slug' => 'onejav',
                'started_at' => CarbonImmutable::parse('2026-11-02T12:15:00Z')->format('Y-m-d H:i:sP'),
                'finished_at' => CarbonImmutable::parse('2026-11-02T12:20:00Z')->format('Y-m-d H:i:sP'),
                'status' => 'completed',
                'movies_new' => 7,
                'movies_updated' => 2,
            ]);

            app(CrawlStatsRollup::class)->rollup($hour, $now);

            $row = DB::table('crawl_stats_hourly')->where('source_slug', '__global__')->first();
            self::assertNotNull($row);
            self::assertSame(7, (int) $row->movies_new);
            self::assertSame(2, (int) $row->movies_updated);
            self::assertSame($hour->toDateTimeString(), CarbonImmutable::parse((string) $row->hour_at)->utc()->toDateTimeString());
        } finally {
            DB::statement("SET TIME ZONE 'UTC'");
        }
    }

    private function insertAttempt(\DateTimeInterface $startedAt, string $outcome, int $status, int $duration): void
    {
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => $startedAt,
            'source_slug' => 'onejav',
            'lane' => 'normal',
            'url_hash' => hash('sha256', (string) $status),
            'method' => 'http',
            'started_at' => $startedAt,
            'duration_ms' => $duration,
            'http_status' => $status,
            'outcome' => $outcome,
        ]);
    }

    private function insertQueue(string $url): void
    {
        DB::table('crawl_queue')->insert([
            'source_slug' => 'onejav',
            'url' => 'https://example.test/' . $url,
            'kind' => 'detail',
            'status' => 'pending',
        ]);
    }
}
