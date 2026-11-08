<?php

declare(strict_types=1);

namespace Tests\Unit\Notifications;

use App\Logging\ArrayLogStore;
use App\Notifications\ActivityLogAlertNotifier;
use JOOservices\LaravelLogging\Contracts\LogStoreInterface;
use Tests\TestCase;

final class ActivityLogAlertNotifierTest extends TestCase
{
    public function test_notify_records_system_alert_via_activity_log(): void
    {
        /** @var ArrayLogStore $store */
        $store = $this->app->make(LogStoreInterface::class);
        self::assertInstanceOf(ArrayLogStore::class, $store);
        $store->flush();

        (new ActivityLogAlertNotifier())->notify(
            'Source unhealthy: javdb',
            'Reason: circuit_open',
            ['source_slug' => 'javdb'],
        );

        $records = $store->all();
        self::assertCount(1, $records);
        self::assertSame('alert.sent', $records[0]->action);
        self::assertSame('Source unhealthy: javdb', $records[0]->message);
        self::assertSame('javdb', $records[0]->context['alert_context']['source_slug'] ?? null);
    }
    public function test_resolved_alert_is_recorded_as_info(): void
    {
        /** @var ArrayLogStore $store */
        $store = $this->app->make(LogStoreInterface::class);
        self::assertInstanceOf(ArrayLogStore::class, $store);
        $store->flush();

        (new ActivityLogAlertNotifier())->notify(
            'Staging readiness recovered',
            'Staging is READY.',
            ['alert_state' => 'resolved', 'severity' => 'info'],
        );

        $records = $store->all();
        self::assertCount(1, $records);
        self::assertSame('alert.resolved', $records[0]->action);
        self::assertSame('info', $records[0]->level);
        self::assertSame('resolved', $records[0]->context['alert_context']['alert_state'] ?? null);
    }
}
