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

    public function test_current_limits_the_configured_name_to_sixty_four_characters(): void
    {
        config(['sentry.node' => str_repeat('x', 70)]);

        self::assertSame(str_repeat('x', 64), NodeIdentity::current());
    }

    public function test_current_falls_back_to_hostname_for_an_unknown_configured_name(): void
    {
        config(['sentry.node' => 'unknown']);

        self::assertSame(NodeIdentity::hostname(), NodeIdentity::current());
    }
}
