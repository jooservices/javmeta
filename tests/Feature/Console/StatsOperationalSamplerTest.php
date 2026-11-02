<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Carbon\CarbonImmutable;
use App\Services\Crawl\CrawlStatsOperationalSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class StatsOperationalSamplerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_hourly_average_queue_depths_from_minute_samples(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        config()->set('javmeta_stats.enabled', true);
        $this->insertQueue('ready-a', 'pending');
        $this->insertQueue('delayed-a', 'pending', now()->copy()->addMinutes(5));
        $this->insertQueue('reserved-a', 'claimed');

        $sampler = app(CrawlStatsOperationalSampler::class);
        $sampler->sample();
        $this->insertQueue('ready-b', 'pending');
        $sampler->sample();

        $this->assertDatabaseHas('crawl_stats_hourly', [
            'source_slug' => '__global__',
            'lane' => '__global__',
            'method' => '__global__',
            'queue_ready_avg' => 1.5,
            'queue_delayed_avg' => 1,
            'queue_reserved_avg' => 1,
            'queue_samples' => 2,
        ]);
    }

    public function test_missing_attempt_table_does_not_add_fake_busy_ratio_samples(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-02T12:34:00Z'));
        config()->set('javmeta_stats.enabled', true);
        $hour = now('UTC')->startOfHour();
        DB::table('crawl_stats_hourly')->insert([
            'hour_at' => $hour,
            'source_slug' => '__global__',
            'lane' => '__global__',
            'method' => '__global__',
            'queue_ready_avg' => 0,
            'queue_delayed_avg' => 0,
            'queue_reserved_avg' => 0,
            'playwright_busy_ratio_avg' => 0.375,
            'flaresolverr_busy_ratio_avg' => 0.125,
            'queue_samples' => 1,
            'playwright_samples' => 2,
            'flaresolverr_samples' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Schema::drop('crawl_fetch_attempts');

        app(CrawlStatsOperationalSampler::class)->sample();

        $this->assertDatabaseHas('crawl_stats_hourly', [
            'hour_at' => $hour->toDateTimeString(),
            'source_slug' => '__global__',
            'playwright_busy_ratio_avg' => 0.375,
            'flaresolverr_busy_ratio_avg' => 0.125,
            'playwright_samples' => 2,
            'flaresolverr_samples' => 3,
        ]);
    }

    private function insertQueue(string $url, string $status, ?\DateTimeInterface $nextAttemptAt = null): void
    {
        DB::table('crawl_queue')->insert([
            'source_slug' => 'onejav',
            'url' => 'https://example.test/' . $url,
            'kind' => 'detail',
            'status' => $status,
            'next_attempt_at' => $nextAttemptAt,
        ]);
    }
}
