<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Models\ReportSnapshot;
use App\Services\Report\ReportSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ReportScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'javmeta_report.enabled' => true,
            'javmeta_report.interval_minutes' => 120,
            'javmeta_report.timezone' => 'America/Mexico_City',
            'javmeta_report.quiet_hours_start' => '',
            'javmeta_report.quiet_hours_end' => '',
        ]);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_default_grid_is_aligned_to_even_hours_in_the_configured_timezone(): void
    {
        $schedule = new ReportSchedule();

        self::assertSame('2026-11-02 18:00', $schedule->dueSlot(CarbonImmutable::parse('2026-11-02 18:00:14 America/Mexico_City'))?->format('Y-m-d H:i'));
        self::assertNull($schedule->dueSlot(CarbonImmutable::parse('2026-11-02 19:00:00 America/Mexico_City')));
    }

    public function test_quiet_grid_slot_is_skipped_without_a_later_catch_up(): void
    {
        config(['javmeta_report.quiet_hours_start' => '22:00', 'javmeta_report.quiet_hours_end' => '06:00']);
        $schedule = new ReportSchedule();

        self::assertNull($schedule->dueSlot(CarbonImmutable::parse('2026-11-03 02:00:00 America/Mexico_City')));
        self::assertNull($schedule->dueSlot(CarbonImmutable::parse('2026-11-03 02:01:00 America/Mexico_City')));
        self::assertSame('2026-11-03 06:00', $schedule->dueSlot(CarbonImmutable::parse('2026-11-03 06:00:00 America/Mexico_City'))?->format('Y-m-d H:i'));
    }

    public function test_schedule_waits_for_the_interval_after_the_last_scheduled_report(): void
    {
        ReportSnapshot::query()->create([
            'scheduled_for' => CarbonImmutable::parse('2026-11-02 16:00:00 America/Mexico_City')->utc(),
            'result' => 'sent',
            'channel' => 'telegram',
            'rendered_text' => 'Previous',
            'raw_numbers' => [],
        ]);
        $schedule = new ReportSchedule();

        self::assertNull($schedule->dueSlot(CarbonImmutable::parse('2026-11-02 17:00:00 America/Mexico_City')));
        self::assertSame('2026-11-02 18:00', $schedule->dueSlot(CarbonImmutable::parse('2026-11-02 18:00:00 America/Mexico_City'))?->format('Y-m-d H:i'));
    }

    public function test_disabled_schedule_is_never_due(): void
    {
        config(['javmeta_report.enabled' => false]);

        self::assertNull((new ReportSchedule())->dueSlot(CarbonImmutable::parse('2026-11-02 18:00:00 America/Mexico_City')));
    }
}
