<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Contracts\ReportChannel;
use App\Models\ReportSnapshot;
use App\Services\Notifications\NotificationPolicyService;
use App\Services\Notifications\NotificationTemplateService;
use App\Services\Report\PeriodicReportService;
use App\Services\Report\ReportDataBuilder;
use App\Services\Report\ReportMetrics;
use App\Services\Report\ReportRenderer;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use RuntimeException;
use Tests\TestCase;

final class PeriodicReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config([
            'cache.default' => 'array',
            'javmeta_report.channels' => ['telegram'],
            'javmeta_report.sections' => [],
            'backup.manifest_path' => storage_path('app/missing-report-manifest-' . uniqid('', true) . '.json'),
        ]);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        ReportSnapshot::flushEventListeners();

        parent::tearDown();
    }

    public function test_scheduled_periodic_reports_bypass_the_manual_policy_cooldown(): void
    {
        config(['javmeta_report.interval_minutes' => 30]);
        $channel = $this->recordingChannel();
        $service = $this->service($channel);
        $firstSlot = CarbonImmutable::parse('2026-11-03 08:00:00 UTC');

        CarbonImmutable::setTestNow($firstSlot);
        self::assertTrue($service->sendScheduled($firstSlot)['sent']);

        $nextSlot = $firstSlot->addMinutes(30);
        CarbonImmutable::setTestNow($nextSlot);
        self::assertTrue($service->sendScheduled($nextSlot)['sent']);

        self::assertCount(2, $channel->deliveries);
        self::assertSame(2, ReportSnapshot::query()->where('report_type', 'periodic')->count());
    }

    public function test_daily_schedule_uses_calendar_slots_across_a_twenty_three_hour_dst_day(): void
    {
        $channel = $this->recordingChannel();
        $service = $this->service($channel);
        $firstSlot = CarbonImmutable::parse('2026-03-07 08:00:00 America/New_York');

        CarbonImmutable::setTestNow($firstSlot);
        self::assertTrue($service->sendDailyScheduled($firstSlot)['sent']);
        self::assertSame('suppressed', $service->sendDailyNow()['result']);

        $nextSlot = CarbonImmutable::parse('2026-03-08 08:00:00 America/New_York');
        self::assertEquals(23, $firstSlot->diffInHours($nextSlot));
        CarbonImmutable::setTestNow($nextSlot);
        self::assertTrue($service->sendDailyScheduled($nextSlot)['sent']);

        self::assertCount(2, $channel->deliveries);
        self::assertSame(2, ReportSnapshot::query()->where('report_type', 'daily')->count());
    }

    public function test_successful_delivery_keeps_slot_and_policy_send_when_snapshot_storage_fails(): void
    {
        $channel = $this->recordingChannel();
        $service = $this->service($channel);
        $slot = CarbonImmutable::parse('2026-11-03 08:00:00 UTC');
        $slotKey = 'javmeta:report:reserved-slot:daily:' . $slot->utc()->format('Y-m-d-H-i');
        $failOnce = true;
        ReportSnapshot::creating(static function () use (&$failOnce): void {
            if ($failOnce) {
                $failOnce = false;
                throw new RuntimeException('Simulated report snapshot storage failure.');
            }
        });

        CarbonImmutable::setTestNow($slot);
        self::assertSame('failed', $service->sendDailyScheduled($slot)['result']);
        self::assertTrue(Cache::has($slotKey));
        self::assertNull(DB::table('notification_policy_events')->where('notification_key', 'report.daily')->value('reserved_at'));
        self::assertNotNull(DB::table('notification_policy_events')->where('notification_key', 'report.daily')->value('last_sent_at'));

        self::assertSame('duplicate', $service->sendDailyScheduled($slot)['result']);
        self::assertCount(1, $channel->deliveries);
        self::assertSame(0, ReportSnapshot::query()->where('report_type', 'daily')->count());
    }

    public function test_policy_suppression_releases_the_scheduled_slot_for_a_retry(): void
    {
        $channel = $this->recordingChannel();
        $service = $this->service($channel);
        $slot = CarbonImmutable::parse('2026-11-03 08:00:00 UTC');
        $slotKey = 'javmeta:report:reserved-slot:daily:' . $slot->utc()->format('Y-m-d-H-i');
        $templates = app(NotificationTemplateService::class);
        $template = $templates->get('report.daily');
        self::assertNotNull($template);
        $templates->save(
            'report.daily',
            (string) $template['title_template'],
            (string) $template['body_template'],
            false,
            (string) $template['default_severity'],
            null,
        );

        CarbonImmutable::setTestNow($slot);
        self::assertSame('suppressed', $service->sendDailyScheduled($slot)['result']);
        self::assertFalse(Cache::has($slotKey));

        $templates->save(
            'report.daily',
            (string) $template['title_template'],
            (string) $template['body_template'],
            true,
            (string) $template['default_severity'],
            null,
        );
        self::assertTrue($service->sendDailyScheduled($slot)['sent']);
        self::assertCount(1, $channel->deliveries);
    }

    public function test_quiet_mode_suppresses_report_sends_without_breaking_previews_or_delivery_afterward(): void
    {
        $policies = app(NotificationPolicyService::class);
        foreach (['report.periodic', 'report.daily'] as $key) {
            $policy = $policies->get($key);
            self::assertNotNull($policy);
            $policies->save($key, [...$policy, 'enabled' => true, 'tier' => 'warning'], null);
        }

        $channel = $this->recordingChannel();
        $service = $this->service($channel);
        $slot = CarbonImmutable::parse('2026-11-03 08:00:00 UTC');
        CarbonImmutable::setTestNow($slot);
        app(SettingsService::class)->set('notifications.quiet_mode', true);

        self::assertArrayHasKey('text', $service->preview());
        self::assertArrayHasKey('text', $service->previewDaily());
        self::assertSame('suppressed', $service->sendScheduled($slot)['result']);
        self::assertSame('suppressed', $service->sendNow()['result']);
        self::assertSame('suppressed', $service->sendDailyScheduled($slot)['result']);
        self::assertSame('suppressed', $service->sendDailyNow()['result']);
        self::assertCount(0, $channel->deliveries);
        self::assertSame(0, ReportSnapshot::query()->count());
        self::assertFalse(Cache::has('javmeta:report:reserved-slot:periodic:' . $slot->utc()->format('Y-m-d-H-i')));
        self::assertFalse(Cache::has('javmeta:report:reserved-slot:daily:' . $slot->utc()->format('Y-m-d-H-i')));

        app(SettingsService::class)->set('notifications.quiet_mode', false);
        CarbonImmutable::setTestNow($slot->addMinute());

        self::assertTrue($service->sendNow()['sent']);
        self::assertSame('suppressed', $service->sendNow()['result']);
        self::assertTrue($service->sendScheduled($slot->addMinute())['sent']);
        self::assertTrue($service->sendDailyNow()['sent']);
        self::assertSame('suppressed', $service->sendDailyNow()['result']);
        self::assertTrue($service->sendDailyScheduled($slot->addMinute())['sent']);

        self::assertCount(4, $channel->deliveries);
        self::assertSame(2, ReportSnapshot::query()->where('report_type', 'periodic')->count());
        self::assertSame(2, ReportSnapshot::query()->where('report_type', 'daily')->count());
    }

    private function service(ReportChannel $channel): PeriodicReportService
    {
        return new PeriodicReportService(
            app(ReportDataBuilder::class),
            app(ReportRenderer::class),
            $channel,
            app(ReportMetrics::class),
            app(\App\Repositories\ReportDataServiceRepository::class),
        );
    }

    /** @return ReportChannel&object{deliveries: list<array{subject: string, body: string, context: array<string, mixed>}>} */
    private function recordingChannel(): ReportChannel
    {
        return new class implements ReportChannel {
            /** @var list<array{subject: string, body: string, context: array<string, mixed>}> */
            public array $deliveries = [];

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->deliveries[] = compact('subject', 'body', 'context');

                return true;
            }
        };
    }
}
