<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Contracts\ReportChannel;
use App\Models\ReportSnapshot;
use App\Services\Report\PeriodicReportService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class ReportSendCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    public array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'javmeta_report.enabled' => true,
            'javmeta_report.interval_minutes' => 120,
            'javmeta_report.timezone' => 'America/Mexico_City',
            'javmeta_report.sections' => ['movies'],
            'javmeta_report.quiet_hours_start' => '',
            'javmeta_report.quiet_hours_end' => '',
        ]);
        Cache::store('array')->flush();
        $this->sent = [];
        $test = $this;
        $this->app->instance(ReportChannel::class, new class ($test) implements ReportChannel {
            public function __construct(private readonly ReportSendCommandTest $test) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->test->sent[] = $subject . "\n" . $body;

                return true;
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_real_scheduled_command_runs_through_the_artisan_kernel_on_its_grid_minute(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 18:00:15 America/Mexico_City'));
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:00:15 America/Mexico_City'));
        $event = collect(Schedule::events())->first(static fn($scheduled): bool => str_contains((string) $scheduled->command, 'report:send'));
        self::assertNotNull($event);
        self::assertSame('* * * * *', $event->expression);

        $command = preg_match('/(?:^|\s)report:send(?:\s|$)/', (string) $event->command);
        self::assertSame(1, $command, 'The registered scheduler event must point at the real Artisan command.');
        Artisan::call('report:send');

        self::assertCount(1, $this->sent);
        self::assertSame('sent', ReportSnapshot::query()->firstOrFail()->getAttribute('result'));
        self::assertSame('2026-11-02 18:00', ReportSnapshot::query()->firstOrFail()->getAttribute('scheduled_for')->timezone('America/Mexico_City')->format('Y-m-d H:i'));
    }

    public function test_daily_report_command_sends_one_report_for_its_configured_local_slot(): void
    {
        config(['javmeta_report.daily_time' => '08:00', 'javmeta_report.daily_timezone' => 'America/Mexico_City']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 08:00:15 America/Mexico_City'));
        Carbon::setTestNow(Carbon::parse('2026-11-02 08:00:15 America/Mexico_City'));

        $event = collect(Schedule::events())->first(static fn($scheduled): bool => str_contains((string) $scheduled->command, 'report:send-daily'));
        self::assertNotNull($event);
        self::assertSame('0 8 * * *', $event->expression);
        self::assertSame('America/Mexico_City', $event->timezone);

        $this->artisan('report:send-daily')->assertSuccessful();
        $this->artisan('report:send-daily')->assertSuccessful();

        self::assertCount(1, $this->sent);
        self::assertSame(1, ReportSnapshot::query()->where('report_type', 'daily')->count());
        self::assertSame('2026-11-02 14:00', ReportSnapshot::query()->firstOrFail()->getAttribute('scheduled_for')->format('Y-m-d H:i'));
    }

    public function test_scheduled_reports_are_not_duplicated_for_one_slot(): void
    {
        $slot = CarbonImmutable::parse('2026-11-02 18:00:00 America/Mexico_City');
        $reports = app(PeriodicReportService::class);

        self::assertTrue($reports->sendScheduled($slot)['sent']);
        self::assertTrue($reports->sendScheduled($slot)['duplicate']);
        self::assertCount(1, $this->sent);
        self::assertSame(1, ReportSnapshot::query()->count());
    }

    public function test_now_sends_during_quiet_hours_without_moving_the_regular_grid(): void
    {
        config(['javmeta_report.enabled' => false, 'javmeta_report.quiet_hours_start' => '00:00', 'javmeta_report.quiet_hours_end' => '23:59']);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-02 17:15:00 America/Mexico_City'));

        $this->artisan('report:send --now')->assertSuccessful();

        $snapshot = ReportSnapshot::query()->firstOrFail();
        self::assertNull($snapshot->getAttribute('scheduled_for'));
        self::assertCount(1, $this->sent);

        config(['javmeta_report.enabled' => true, 'javmeta_report.quiet_hours_start' => '', 'javmeta_report.quiet_hours_end' => '']);
        self::assertSame('2026-11-02 18:00', app(\App\Services\Report\ReportSchedule::class)
            ->dueSlot(CarbonImmutable::parse('2026-11-02 18:00:00 America/Mexico_City'))?->format('Y-m-d H:i'));
    }
}
