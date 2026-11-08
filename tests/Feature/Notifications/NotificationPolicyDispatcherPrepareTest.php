<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationPolicyDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(NotificationPolicyDispatcher::class)]
final class NotificationPolicyDispatcherPrepareTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{?string, array<string, mixed>}> */
    public static function undecidedCases(): iterable
    {
        yield 'a test send is never policed' => ['crawl.dispatch_delayed', ['test_send' => true]];
        yield 'an explicit bypass is never policed' => ['crawl.dispatch_delayed', ['policy_bypass' => true]];
        yield 'no template and no notification key' => [null, []];
        yield 'an empty template key' => ['', []];
        yield 'an empty notification key wins over the template' => ['crawl.dispatch_delayed', ['notification_key' => '']];
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('undecidedCases')]
    public function test_messages_outside_the_policy_get_no_decision(?string $template, array $context): void
    {
        self::assertNull(app(NotificationPolicyDispatcher::class)->prepare($template, $context));
    }

    public function test_a_key_missing_from_the_registry_is_suppressed_with_that_reason(): void
    {
        $decision = app(NotificationPolicyDispatcher::class)->prepare('no.such.notification', ['alert_state' => 'open']);

        self::assertNotNull($decision);
        self::assertFalse($decision['allowed']);
        self::assertSame('registry_missing', $decision['reason']);
        self::assertFalse($decision['resolved']);
    }

    public function test_a_resolved_state_is_recognised_from_the_alert_state_or_the_severity(): void
    {
        $byState = app(NotificationPolicyDispatcher::class)->prepare('no.such.notification', ['alert_state' => 'RESOLVED']);
        $bySeverity = app(NotificationPolicyDispatcher::class)->prepare('no.such.notification', ['severity' => 'Resolved']);

        self::assertTrue($byState['resolved']);
        self::assertTrue($bySeverity['resolved']);
    }
}
