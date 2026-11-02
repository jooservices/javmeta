<?php

declare(strict_types=1);

namespace Tests\Unit\Crawl;

use App\Services\Crawl\CrawlStatsRollup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrawlStatsRollupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_laravel_mutable_carbon_dates(): void
    {
        $from = Carbon::parse('2026-11-02T12:00:00Z');
        $until = Carbon::parse('2026-11-02T12:30:00Z');
        DB::table('crawl_fetch_attempts')->insert([
            'created_at' => $from->copy()->addMinutes(5),
            'source_slug' => 'onejav',
            'lane' => 'normal',
            'url_hash' => hash('sha256', 'mutable-carbon-rollup'),
            'method' => 'http',
            'started_at' => $from->copy()->addMinutes(5),
            'duration_ms' => 30,
            'http_status' => 200,
            'outcome' => 'ok',
        ]);

        $result = app(CrawlStatsRollup::class)->rollup($from, $until);

        self::assertSame(1, $result['attempt_rows']);
        $this->assertDatabaseHas('crawl_stats_hourly', [
            'hour_at' => '2026-11-02 12:00:00',
            'source_slug' => 'onejav',
            'lane' => 'normal',
            'method' => 'http',
            'attempts' => 1,
            'ok' => 1,
        ]);
    }
}
