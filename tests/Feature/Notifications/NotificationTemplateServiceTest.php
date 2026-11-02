<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;
use App\Observability\NotificationMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NotificationTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_is_idempotent_and_does_not_overwrite_edits(): void
    {
        $service = app(NotificationTemplateService::class);
        $service->seed();
        $original = $service->get('crawl.dispatch_delayed');
        self::assertNotNull($original);

        $service->save('crawl.dispatch_delayed', 'Custom title', 'Custom body', true, 'critical', 42);
        $service->seed();

        self::assertSame('Custom title', $service->get('crawl.dispatch_delayed')['title_template']);
        self::assertSame('Custom body', $service->get('crawl.dispatch_delayed')['body_template']);
        self::assertSame(count(app(NotificationRegistry::class)->all()), $service->count());
    }

    public function test_restore_reinstates_a_previous_template_revision(): void
    {
        $service = app(NotificationTemplateService::class);
        $service->seed();
        $service->save('crawl.dispatch_delayed', 'First title', 'First body', true, 'critical', 42);
        $service->save('crawl.dispatch_delayed', 'Second title', 'Second body', false, 'warning', 43);
        $revisionId = $service->revisions('crawl.dispatch_delayed')[0]['id'];

        $service->restore('crawl.dispatch_delayed', $revisionId, 42);

        $restored = $service->get('crawl.dispatch_delayed');
        self::assertSame('First title', $restored['title_template']);
        self::assertSame('First body', $restored['body_template']);
        self::assertTrue($restored['enabled']);
        self::assertSame('critical', $restored['default_severity']);
    }

    public function test_successful_delivery_counts_are_exported_as_bounded_metric_points(): void
    {
        $service = app(NotificationTemplateService::class);
        $service->markSent('follow.match');
        $service->markSent('follow.match');

        $point = collect(app(NotificationMetrics::class)->points())
            ->first(static fn(array $row): bool => $row['labels']['notification_key'] === 'follow.match');

        self::assertSame(2, $point['value']);
        self::assertSame('telegram', $point['labels']['channel']);
        self::assertSame(2, $service->get('follow.match')['sent_count']);
    }
}
