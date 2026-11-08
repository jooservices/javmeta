<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Services\Crawl\FeedPoolStopReason;
use PHPUnit\Framework\TestCase;

final class FeedPoolStopReasonTest extends TestCase
{
    public function test_claim_stop_reason_identifies_budget_inflight_and_empty_candidates(): void
    {
        self::assertSame('exhausted_budget', FeedPoolStopReason::forClaim(true, false));
        self::assertSame('per_source_inflight', FeedPoolStopReason::forClaim(false, true));
        self::assertSame('no_due_rows', FeedPoolStopReason::forClaim(false, false));
    }

    public function test_queue_stop_reason_classifies_early_and_capacity_gates(): void
    {
        self::assertSame('interval_not_elapsed', FeedPoolStopReason::forQueue(false, true, true, true));
        self::assertSame('advisory_lock', FeedPoolStopReason::forQueue(true, false, true, true));
        self::assertSame('global_cap', FeedPoolStopReason::forQueue(true, true, false, false));
        self::assertSame('depth', FeedPoolStopReason::forQueue(true, true, true, false));
        self::assertSame('no_due_rows', FeedPoolStopReason::forQueue(true, true, true, true, 'no_due_rows'));
        self::assertNull(FeedPoolStopReason::forQueue(true, true, true, true));
    }
}
