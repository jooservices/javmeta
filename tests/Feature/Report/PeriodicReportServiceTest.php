<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Contracts\ReportChannel;
use App\Models\ReportSnapshot;
use App\Services\Report\PeriodicReportService;
use App\Services\Report\ReportDataBuilder;
use App\Services\Report\ReportMetrics;
use App\Services\Report\ReportRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

final class PeriodicReportServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    public function test_snapshot_failure_releases_policy_and_slot_reservations_for_retry(): void
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
        self::assertFalse(Cache::has($slotKey));
        self::assertNull(DB::table('notification_policy_events')->where('notification_key', 'report.daily')->value('reserved_at'));

        self::assertTrue($service->sendDailyScheduled($slot)['sent']);
        self::assertCount(2, $channel->deliveries);
        self::assertSame(1, ReportSnapshot::query()->where('report_type', 'daily')->count());
    }

    private function service(ReportChannel $channel): PeriodicReportService
    {
        return new PeriodicReportService(
            app(ReportDataBuilder::class),
            app(ReportRenderer::class),
            $channel,
            app(ReportMetrics::class),
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
