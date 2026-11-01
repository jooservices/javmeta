<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\Source;
use App\Services\Crawl\CrawlLaneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CrawlLaneServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_static_computed_and_manual_assignment_and_disabled_split(): void
    {
        $lanes = app(CrawlLaneService::class);
        Source::factory()->create(['slug' => 'heyzo', 'browser_lane' => true]);
        $this->assertSame('browser', $lanes->lane('javbus'));
        $this->assertSame('browser', $lanes->lane('heyzo'));
        $this->assertSame('direct', $lanes->lane('xcity'));
        config(['javmeta_queues.lane_direct_pins' => ['javbus', 'heyzo'], 'javmeta_queues.lane_browser_pins' => ['xcity']]);
        $this->assertSame('direct', $lanes->lane('javbus'));
        $this->assertSame('direct', $lanes->lane('heyzo'));
        $this->assertSame('browser', $lanes->lane('xcity'));
        config(['javmeta_queues.lanes_enabled' => false]);
        $this->assertSame('direct', $lanes->lane('xcity'));
    }

    public function test_recompute_uses_24_hours_minimum_and_inclusive_share_and_clears_old_decisions(): void
    {
        $this->freezeTime();
        foreach (['threshold', 'below', 'few', 'old', 'skipped'] as $slug) {
            Source::factory()->create(['slug' => $slug, 'browser_lane' => true]);
        }
        foreach (['threshold' => 10, 'below' => 9, 'few' => 10, 'old' => 40, 'skipped' => 40] as $slug => $browser) {
            for ($i = 0; $i < ($slug === 'few' ? 39 : 40); $i++) {
                DB::table('crawl_fetch_attempts')->insert([
                    'source_slug' => $slug, 'created_at' => $slug === 'old' ? now()->subHours(25) : now(),
                    'url_hash' => hash('sha256', $slug . $i), 'method' => $i < $browser ? 'playwright' : 'http',
                    'started_at' => now(), 'duration_ms' => 1, 'outcome' => $slug === 'skipped' ? 'skipped' : 'ok',
                ]);
            }
        }
        $lanes = app(CrawlLaneService::class);
        $lanes->recompute();
        $this->assertSame('browser', $lanes->lane('threshold'));
        foreach (['below', 'few', 'old', 'skipped'] as $slug) {
            $this->assertSame('direct', $lanes->lane($slug));
        }
        config(['javmeta_queues.lane_min_attempts' => 39, 'javmeta_queues.lane_browser_percent' => 20]);
        $lanes->recompute();
        $this->assertSame('browser', $lanes->lane('few'));
        $this->assertSame('browser', $lanes->lane('below'));
    }
}
