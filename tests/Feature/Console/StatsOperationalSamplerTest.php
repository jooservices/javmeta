<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Services\Crawl\CrawlStatsOperationalSampler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StatsOperationalSamplerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_hourly_average_queue_depths_from_minute_samples(): void
    {
        $this->freezeTime();
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
