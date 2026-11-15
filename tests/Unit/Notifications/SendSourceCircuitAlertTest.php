<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Events\SourceCircuitOpened;
use App\Contracts\AlertNotifier;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use DateTimeImmutable;
use Tests\TestCase;

final class SendSourceCircuitAlertTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_event_sends_an_english_source_pause_notification(): void
    {
        $resumeAt = new DateTimeImmutable('2026-11-06T12:05:00+00:00');
        $notifier = new class implements AlertNotifier {
            /** @var list<array{subject: string, body: string, context: array<string, mixed>}> */
            public array $messages = [];

            public function notify(string $subject, string $body, array $context = []): bool
            {
                $this->messages[] = compact('subject', 'body', 'context');

                return true;
            }
        };
        $alerts = new AlertDispatcher($notifier);
        $this->app->instance(AlertDispatcher::class, $alerts);

        Event::dispatch(new SourceCircuitOpened('javbus', 3, 300, $resumeAt));

        self::assertCount(1, $notifier->messages);
        self::assertStringContainsString('Source javbus has been paused', $notifier->messages[0]['subject']);
        self::assertStringContainsString('resume automatically at 2026-11-06T12:05:00+00:00', $notifier->messages[0]['body']);
        self::assertSame('crawl.source_circuit_open', $notifier->messages[0]['context']['template_key']);
    }
}
