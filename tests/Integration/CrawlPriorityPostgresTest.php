<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;

final class CrawlPriorityPostgresTest extends IntegrationTestCase
{
    public function test_postgres_claims_in_priority_order_and_stops_a_level_at_its_cap(): void
    {
        $this->travelTo('2026-11-28T12:00:00Z');
        config([
            'javmeta_queues.lanes_enabled' => true,
            'javmeta_queues.browser_sources' => [],
            'javmeta_queues.priority.high_sources' => ['vip-a', 'vip-b'],
            'javmeta_queues.priority.low_sources' => ['bulk'],
            'javmeta_queues.priority.recrawl_is_low' => true,
            'javmeta_queues.priority.caps' => ['high' => 1, 'normal' => 0, 'low' => 0],
        ]);
        foreach (['bulk', 'again', 'plain', 'vip-a', 'vip-b'] as $slug) {
            Source::factory()->create(['slug' => $slug, 'enabled' => true]);
            CrawlQueue::factory()->create(['source_slug' => $slug, 'kind' => 'detail', 'is_recrawl' => $slug === 'again']);
        }
        $service = app(CrawlQueueService::class);

        $first = $service->claimNext('worker', null, [], 'direct');
        self::assertSame('vip-a', $first?->source_slug);
        self::assertSame('plain', $service->claimNext('worker', null, [], 'direct')?->source_slug, 'The only high slot is taken.');
        self::assertSame('bulk', $service->claimNext('worker', null, [], 'direct')?->source_slug, 'Low rows are served in queue order; the recrawl comes after.');
        self::assertSame('again', $service->claimNext('worker', null, [], 'direct')?->source_slug);
        self::assertNull($service->claimNext('worker', null, [], 'direct'), 'vip-b waits for the high slot.');

        $first->forceFill(['status' => CrawlQueue::STATUS_DONE])->save();
        self::assertSame('vip-b', $service->claimNext('worker', null, [], 'direct')?->source_slug);
    }
}
