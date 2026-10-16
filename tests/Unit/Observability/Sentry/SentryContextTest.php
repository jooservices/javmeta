<?php

declare(strict_types=1);

namespace Tests\Unit\Observability\Sentry;

use App\Observability\Sentry\SentryContext;
use Sentry\Event;
use Sentry\State\Scope;
use Tests\TestCase;

final class SentryContextTest extends TestCase
{
    public function test_context_applies_configured_role_and_node_tags_to_event_and_scope(): void
    {
        config()->set(['sentry.role' => 'worker', 'sentry.node' => 'crawl-01']);
        $event = Event::createEvent();
        $scope = new Scope();

        SentryContext::applyToScope($scope);
        $scope->applyToEvent($event);
        SentryContext::applyToEvent($event);

        self::assertSame(['role' => 'worker', 'node' => 'crawl-01'], $event->getTags());
    }
}
