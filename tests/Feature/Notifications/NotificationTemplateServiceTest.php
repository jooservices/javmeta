<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;
use App\Observability\NotificationMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
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

    public function test_default_template_lookup_and_missing_policy_reads_are_safe(): void
    {
        $service = app(NotificationTemplateService::class);
        DB::table('notification_templates')->where('key', 'crawl.dispatch_delayed')->delete();
        $template = $service->get('crawl.dispatch_delayed');

        self::assertNotNull($template);
        self::assertNull($template['id']);
        self::assertSame(1, $template['version']);
        self::assertSame(0, $template['sent_count']);
        self::assertNull($service->findStored('unsupported.notification'));
        self::assertNull($service->lastKnownEnabled('missing.notification'));
        self::assertSame((bool) $template['enabled'], $service->lastKnownEnabled('crawl.dispatch_delayed'));
    }

    public function test_invalid_template_and_policy_updates_are_rejected(): void
    {
        $service = app(NotificationTemplateService::class);

        try {
            $service->save('unknown.notification', 'Title', 'Body', true, 'notice', null);
            self::fail('An unsupported notification key must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The notification type is not supported.', $exception->getMessage());
        }

        try {
            $service->save('crawl.dispatch_delayed', 'Title', 'Body', true, 'unsupported', null);
            self::fail('An unsupported severity must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Choose a supported default severity.', $exception->getMessage());
        }

        try {
            $service->save('crawl.dispatch_delayed', 'Title', 'Body', true, 'notice', null, ['tier' => 'bad']);
            self::fail('An incomplete policy must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The notification policy values are invalid.', $exception->getMessage());
        }

        try {
            $service->restore('unknown.notification', 1, null);
            self::fail('An unsupported notification key must not be restored.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The notification type is not supported.', $exception->getMessage());
        }

        $service->seed();
        try {
            $service->restore('crawl.dispatch_delayed', PHP_INT_MAX, null);
            self::fail('An unknown revision must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The selected revision does not exist for this notification.', $exception->getMessage());
        }
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

    public function test_stale_template_read_cannot_replace_the_enabled_cache_after_a_save(): void
    {
        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        $service = app(NotificationTemplateService::class);
        $template = $service->get('crawl.dispatch_delayed');
        self::assertNotNull($template);
        $service->save(
            'crawl.dispatch_delayed',
            (string) $template['title_template'],
            (string) $template['body_template'],
            true,
            (string) $template['default_severity'],
            null,
        );

        $saveStarted = false;
        DB::listen(function (QueryExecuted $query) use ($service, $template, &$saveStarted): void {
            if ($saveStarted || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'notification_templates')) {
                return;
            }
            $saveStarted = true;
            $service->save(
                'crawl.dispatch_delayed',
                (string) $template['title_template'],
                (string) $template['body_template'],
                false,
                (string) $template['default_severity'],
                null,
            );
        });

        $staleRead = $service->get('crawl.dispatch_delayed');

        self::assertTrue($saveStarted);
        self::assertTrue($staleRead['enabled']);
        self::assertFalse($service->lastKnownEnabled('crawl.dispatch_delayed'));
    }
}
