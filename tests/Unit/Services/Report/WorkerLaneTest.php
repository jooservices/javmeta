<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use App\Services\Report\WorkerLane;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(WorkerLane::class)]
final class WorkerLaneTest extends TestCase
{
    public function test_a_queue_whose_name_contains_a_browser_queue_name_is_the_browser_lane(): void
    {
        config(['javmeta_queues.browser_names' => ['browser_listing', 'browser_detail']]);

        self::assertSame('browser', WorkerLane::fromQueue('browser_listing'));
        self::assertSame('browser', WorkerLane::fromQueue('redis:browser_detail'));
        self::assertSame('browser', WorkerLane::fromQueue('x_browser_listing_retry'));
    }

    public function test_every_other_queue_is_the_direct_lane(): void
    {
        config(['javmeta_queues.browser_names' => ['browser_listing']]);

        self::assertSame('direct', WorkerLane::fromQueue('listing'));
        self::assertSame('direct', WorkerLane::fromQueue('redis:detail'));

        config(['javmeta_queues.browser_names' => []]);
        self::assertSame('direct', WorkerLane::fromQueue('browser_listing'));
    }
}
