<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CrawlPriorityClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-28T12:00:00Z'));
        config()->set([
            'javmeta_queues.lanes_enabled' => true,
            'javmeta_queues.browser_sources' => ['bro-a', 'bro-b'],
            'javmeta_queues.priority.high_sources' => [],
            'javmeta_queues.priority.low_sources' => [],
            'javmeta_queues.priority.recrawl_is_low' => true,
            'javmeta_queues.priority.caps' => ['high' => 0, 'normal' => 0, 'low' => 0],
        ]);
    }

    public function test_the_direct_lane_claims_high_before_normal_before_low(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip'], 'javmeta_queues.priority.low_sources' => ['bulk']]);
        $this->queue('bulk');
        $this->queue('plain');
        $this->queue('vip');

        self::assertSame(['vip', 'plain', 'bulk'], $this->claimedSources(3));
    }

    public function test_rows_of_one_level_keep_the_order_of_the_queue(): void
    {
        $this->queue('first');
        $this->queue('second');
        $this->queue('third');

        self::assertSame(['first', 'second', 'third'], $this->claimedSources(3));
    }

    public function test_a_recrawl_runs_after_new_work_unless_its_source_is_high(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip']]);
        $this->queue('again', recrawl: true);
        $this->queue('vip', recrawl: true);
        $this->queue('fresh');

        self::assertSame(['vip', 'fresh', 'again'], $this->claimedSources(3));
    }

    public function test_a_recrawl_is_normal_when_the_rule_is_switched_off(): void
    {
        config()->set(['javmeta_queues.priority.recrawl_is_low' => false]);
        $this->queue('again', recrawl: true);
        $this->queue('fresh');

        self::assertSame(['again', 'fresh'], $this->claimedSources(2));
    }

    public function test_the_default_settings_keep_the_order_of_the_queue_for_new_work(): void
    {
        foreach (['one', 'two', 'three', 'four'] as $source) {
            $this->queue($source);
        }

        self::assertSame(['one', 'two', 'three', 'four'], $this->claimedSources(4));
    }

    public function test_a_level_at_its_cap_is_skipped_until_a_slot_frees(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip-a', 'vip-b'], 'javmeta_queues.priority.caps' => ['high' => 1, 'normal' => 0, 'low' => 0]]);
        $this->queue('vip-a');
        $this->queue('vip-b');
        $this->queue('plain');

        $first = $this->claim();
        self::assertSame('vip-a', $first?->source_slug);
        self::assertSame('plain', $this->claim()?->source_slug, 'The only high slot is taken, so the next claim goes to the next level.');
        self::assertNull($this->claim(), 'The second high row waits for a slot.');

        $first->forceFill(['status' => CrawlQueue::STATUS_DONE])->save();
        self::assertSame('vip-b', $this->claim()?->source_slug);
    }

    public function test_a_cap_of_zero_means_no_cap(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip-a', 'vip-b'], 'javmeta_queues.priority.caps' => ['high' => 0, 'normal' => 0, 'low' => 0]]);
        $this->queue('vip-a');
        $this->queue('vip-b');

        self::assertSame(['vip-a', 'vip-b'], $this->claimedSources(2));
    }

    public function test_the_browser_lane_ignores_priority(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['bro-b'], 'javmeta_queues.priority.low_sources' => ['bro-a']]);
        $this->queue('bro-a');
        $this->queue('bro-b');

        self::assertSame(['bro-a', 'bro-b'], $this->claimedSources(2, 'browser'));
    }

    public function test_a_claim_without_a_lane_ignores_priority(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip']]);
        $this->queue('plain');
        $this->queue('vip');

        self::assertSame(['plain', 'vip'], $this->claimedSources(2, null));
    }

    public function test_the_retry_lane_ignores_priority(): void
    {
        config()->set(['javmeta_queues.priority.high_sources' => ['vip']]);
        $this->queue('plain', status: CrawlQueue::STATUS_RETRY_PENDING);
        $this->queue('vip', status: CrawlQueue::STATUS_RETRY_PENDING);

        self::assertSame(['plain', 'vip'], $this->claimedSources(2, 'retry'));
    }

    /** @return list<string> */
    private function claimedSources(int $count, ?string $lane = 'direct'): array
    {
        $sources = [];
        for ($index = 0; $index < $count; $index++) {
            $row = $this->claim($lane);
            if ($row instanceof CrawlQueue) {
                $sources[] = $row->source_slug;
            }
        }

        return $sources;
    }

    private function claim(?string $lane = 'direct'): ?CrawlQueue
    {
        return app(CrawlQueueService::class)->claimNext('worker', null, [], $lane);
    }

    private function queue(string $slug, bool $recrawl = false, string $status = CrawlQueue::STATUS_PENDING): void
    {
        Source::query()->firstOrCreate(['slug' => $slug], Source::factory()->make(['slug' => $slug, 'enabled' => true])->getAttributes());
        CrawlQueue::query()->create([
            'source_slug' => $slug,
            'url' => 'https://example.test/' . $slug . '/' . CrawlQueue::query()->count(),
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => $status,
            'attempts' => 0,
            'max_attempts' => 3,
            'next_attempt_at' => now()->subSecond(),
            'is_recrawl' => $recrawl,
        ]);
    }
}
