<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Events\CrawlSourceUnhealthy;
use App\Models\Movie;
use App\Models\MovieSource;
use App\Models\ReviewFlag;
use App\Models\Source;
use App\Services\Crawl\TitleFailureTracker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class TitleFailureTrackerTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft404_sets_delisted_at_without_wiping_movie(): void
    {
        Event::fake([CrawlSourceUnhealthy::class]);

        $movie = Movie::factory()->create([
            'display_code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'title_jp' => 'Keep me',
            'delisted_at' => null,
        ]);

        MovieSource::query()->create([
            'movie_id' => $movie->id,
            'site_slug' => 'javdb',
            'mongo_id' => fake()->uuid(),
            'source_url' => 'https://javdb.com/v/ssis001',
            'source_code' => 'SSIS-001',
            'crawled_at' => now(),
        ]);

        Source::query()->create([
            'slug' => 'javdb',
            'name' => 'JavDB',
            'base_url' => 'https://javdb.com',
            'enabled' => true,
            'priority' => 10,
            'needs_proxy' => false,
            'gap_seconds_default' => 20,
            'gap_seconds_min' => 20,
            'gap_seconds_max' => 60,
            'gap_seconds_current' => 20,
        ]);

        app(TitleFailureTracker::class)->recordFailure(
            'javdb',
            'https://javdb.com/v/ssis001',
            'Page not found',
            true,
        );

        $movie->refresh();
        $this->assertNotNull($movie->delisted_at);
        $this->assertSame('Keep me', $movie->title_jp);
        Event::assertDispatched(CrawlSourceUnhealthy::class);
    }

    public function test_consecutive_failures_flag_needs_review(): void
    {
        Event::fake([CrawlSourceUnhealthy::class]);
        config(['javmeta_alerts.title_failure_threshold' => 2]);

        $movie = Movie::factory()->create([
            'display_code' => 'SSIS-002',
            'code_normalized' => 'SSIS002',
            'needs_review' => false,
        ]);

        MovieSource::query()->create([
            'movie_id' => $movie->id,
            'site_slug' => 'onejav',
            'mongo_id' => fake()->uuid(),
            'source_url' => 'https://onejav.com/torrent/ssis002',
            'source_code' => 'SSIS-002',
            'crawled_at' => now(),
        ]);

        $tracker = app(TitleFailureTracker::class);
        $tracker->recordFailure('onejav', 'https://onejav.com/torrent/ssis002', 'timeout');
        $tracker->recordFailure('onejav', 'https://onejav.com/torrent/ssis002', 'timeout');

        $this->assertTrue($movie->refresh()->needs_review);
        $this->assertDatabaseHas('review_flags', [
            'movie_id' => $movie->id,
            'reason' => 'consecutive_crawl_failure',
            'consecutive_failures' => 2,
        ]);
        $this->assertInstanceOf(ReviewFlag::class, ReviewFlag::query()->where('movie_id', $movie->id)->first());
    }
}
