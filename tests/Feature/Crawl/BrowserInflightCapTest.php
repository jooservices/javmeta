<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BrowserInflightCapTest extends TestCase
{
    use RefreshDatabase;

    private function row(string $source, string $status): void
    {
        CrawlQueue::query()->create(['source_slug' => $source, 'url' => 'https://' . $source . '.test/' . uniqid(), 'kind' => 'listing', 'status' => $status, 'attempts' => 0, 'max_attempts' => 3, 'next_attempt_at' => now()->subMinute()]);
    }

    public function test_browser_sources_share_one_cap_and_other_sources_are_not_held_back(): void
    {
        config(['javmeta_queues.browser_sources' => ['javbus', 'missav'], 'javmeta_queues.browser_inflight' => 2, 'javmeta_queues.per_source_inflight' => 10]);
        $this->row('javbus', CrawlQueue::STATUS_CLAIMED);
        $this->row('missav', CrawlQueue::STATUS_CLAIMED);
        $this->row('javbus', CrawlQueue::STATUS_PENDING);
        $this->row('missav', CrawlQueue::STATUS_PENDING);
        $this->row('javdb', CrawlQueue::STATUS_PENDING);

        $claimed = app(CrawlQueueService::class)->claimNext('w1');

        $this->assertNotNull($claimed);
        $this->assertSame('javdb', $claimed->source_slug, 'the cap is full, so only a source without the browser is claimed');
        $this->assertNull(app(CrawlQueueService::class)->claimNext('w1'), 'nothing else is claimable until a browser row finishes');
    }

    public function test_a_cap_of_zero_means_no_cap(): void
    {
        config(['javmeta_queues.browser_sources' => ['javbus'], 'javmeta_queues.browser_inflight' => 0, 'javmeta_queues.per_source_inflight' => 10]);
        $this->row('javbus', CrawlQueue::STATUS_CLAIMED);
        $this->row('javbus', CrawlQueue::STATUS_PENDING);

        $this->assertSame('javbus', app(CrawlQueueService::class)->claimNext('w1')?->source_slug);
    }
}
