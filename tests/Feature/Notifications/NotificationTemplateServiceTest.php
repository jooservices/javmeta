<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;
use App\Observability\NotificationMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $registryKeys = array_keys(app(NotificationRegistry::class)->all());
        $storedKeys = DB::table('notification_templates')->orderBy('key')->pluck('key')->all();
        sort($registryKeys);
        self::assertSame($registryKeys, $storedKeys);
        self::assertSame(count($registryKeys), $service->count());
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

    public function test_saving_an_unchanged_warning_policy_does_not_create_a_revision(): void
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->get('crawl.success_rate_low');
        self::assertNotNull($template);
        $version = $template['version'];

        $service->save(
            'crawl.success_rate_low',
            $template['title_template'],
            $template['body_template'],
            $template['enabled'],
            $template['default_severity'],
            $template['updated_by'],
            [
                'tier' => $template['tier'],
                'cooldown_minutes' => $template['cooldown_minutes'],
                'escalation_minutes' => $template['escalation_minutes'],
                'include_in_periodic_report' => $template['include_in_periodic_report'],
                'include_in_daily_report' => $template['include_in_daily_report'],
                'send_resolved' => $template['send_resolved'],
            ],
        );

        self::assertSame($version, $service->get('crawl.success_rate_low')['version']);
        self::assertSame([], $service->revisions('crawl.success_rate_low'));
    }

    public function test_restore_preserves_disabled_escalation_from_a_policy_revision(): void
    {
        $service = app(NotificationTemplateService::class);
        $template = $service->get('crawl.success_rate_low');
        self::assertNotNull($template);
        $policy = [
            'tier' => $template['tier'],
            'cooldown_minutes' => $template['cooldown_minutes'],
            'escalation_minutes' => null,
            'include_in_periodic_report' => $template['include_in_periodic_report'],
            'include_in_daily_report' => $template['include_in_daily_report'],
            'send_resolved' => $template['send_resolved'],
        ];
        $service->save('crawl.success_rate_low', 'Disabled escalation', $template['body_template'], true, $template['default_severity'], null, $policy);
        $service->save('crawl.success_rate_low', 'Current title', $template['body_template'], true, $template['default_severity'], null, $policy);
        $revisionId = (int) $service->revisions('crawl.success_rate_low')[0]['id'];

        $service->restore('crawl.success_rate_low', $revisionId, null);

        self::assertNull($service->get('crawl.success_rate_low')['escalation_minutes']);
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
