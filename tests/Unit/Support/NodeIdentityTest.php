<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NodeIdentity;
use Tests\TestCase;

final class NodeIdentityTest extends TestCase
{
    public function test_current_uses_the_trimmed_and_normalized_configured_name(): void
    {
        config(['sentry.node' => ' xcrawler worker:1 ']);

        self::assertSame('xcrawler-worker-1', NodeIdentity::current());
    }

    public function test_current_keeps_long_configured_names_distinct_within_sixty_four_characters(): void
    {
        $sharedPrefix = str_repeat('x', 70);
        config(['sentry.node' => $sharedPrefix . '-one']);
        $firstNode = NodeIdentity::current();

        config(['sentry.node' => $sharedPrefix . '-two']);
        $secondNode = NodeIdentity::current();

        self::assertSame(64, mb_strlen($firstNode));
        self::assertSame(64, mb_strlen($secondNode));
        self::assertNotSame($firstNode, $secondNode);
    }

    public function test_current_falls_back_to_hostname_for_an_unknown_configured_name(): void
    {
        config(['sentry.node' => 'unknown']);

        self::assertSame(NodeIdentity::hostname(), NodeIdentity::current());
    }
}
