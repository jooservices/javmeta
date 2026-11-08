<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\NodeIdentity;
use Tests\TestCase;

final class NodeIdentityTest extends TestCase
{
    public function test_current_hashes_a_configured_name_when_normalization_changes_it(): void
    {
        config(['sentry.node' => ' xcrawler worker:1 ']);

        self::assertSame(
            'xcrawler-worker-1-' . substr(hash('sha256', 'xcrawler worker:1'), 0, 32),
            NodeIdentity::current(),
        );
    }

    public function test_current_keeps_configured_names_that_normalize_to_the_same_slug_distinct(): void
    {
        config(['sentry.node' => 'worker/a']);
        $slash = NodeIdentity::current();

        config(['sentry.node' => 'worker a']);
        $space = NodeIdentity::current();

        self::assertNotSame($slash, $space);
    }

    public function test_current_keeps_names_that_need_no_normalization_unchanged(): void
    {
        config(['sentry.node' => 'worker-a']);

        self::assertSame('worker-a', NodeIdentity::current());
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
