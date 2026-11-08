<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Contracts\ReportChannel;
use App\Models\ReportSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Characterises the report:send, report:send-daily and javmeta:hourly-report branches. */
final class ReportCommandsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $delivered = [];

    private bool $deliveryResult = true;

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
        $this->delivered = [];
        $this->deliveryResult = true;

        $test = $this;
        $this->app->instance(ReportChannel::class, new class ($test) implements ReportChannel {
            public function __construct(private readonly ReportCommandsTest $test) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                return $this->test->record($subject . "\n" . $body);
            }
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function record(string $message): bool
    {
        $this->delivered[] = $message;

        return $this->deliveryResult;
    }

    public function test_hourly_report_dry_run_prints_the_message_and_sends_nothing(): void
    {
        $this->artisan('javmeta:hourly-report', ['--dry-run' => true])
            ->expectsOutputToContain('[XCrawler] Report')
            ->assertSuccessful();

        self::assertSame([], $this->delivered);
        self::assertSame(0, ReportSnapshot::query()->count());
    }

    public function test_hourly_report_fails_when_the_channel_does_not_deliver(): void
    {
        $this->deliveryResult = false;

        $this->artisan('javmeta:hourly-report')
            ->expectsOutputToContain('[XCrawler] Report')
            ->expectsOutputToContain('The report was not delivered.')
            ->assertFailed();

        self::assertCount(1, $this->delivered);
        self::assertSame('failed', ReportSnapshot::query()->firstOrFail()->getAttribute('result'));
    }

    public function test_report_send_does_nothing_when_no_slot_is_due(): void
    {
        $now = CarbonImmutable::parse('2026-11-02 18:07:00 America/Mexico_City');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow(Carbon::parse('2026-11-02 18:07:00 America/Mexico_City'));

        $this->artisan('report:send')
            ->expectsOutputToContain('No periodic report is due.')
            ->assertSuccessful();

        self::assertSame([], $this->delivered);
    }

    public function test_report_send_now_prints_the_report_and_records_a_manual_snapshot(): void
    {
        $this->artisan('report:send', ['--now' => true])
            ->expectsOutputToContain('[XCrawler] Report')
            ->assertSuccessful();

        self::assertCount(1, $this->delivered);
        $snapshot = ReportSnapshot::query()->firstOrFail();
        self::assertSame('sent', $snapshot->getAttribute('result'));
        self::assertNull($snapshot->getAttribute('scheduled_for'));
    }

    public function test_report_send_now_fails_when_the_channel_does_not_deliver(): void
    {
        $this->deliveryResult = false;

        $this->artisan('report:send', ['--now' => true])
            ->expectsOutputToContain('The report was not delivered.')
            ->assertFailed();
    }

    public function test_report_send_now_reports_a_duplicate_while_another_manual_send_holds_the_lock(): void
    {
        $lock = Cache::lock('javmeta:report:send:periodic:manual', 300);
        self::assertTrue($lock->get());

        $this->artisan('report:send', ['--now' => true])
            ->expectsOutputToContain('This report slot has already been handled.')
            ->assertSuccessful();

        self::assertSame([], $this->delivered);
        $lock->release();
    }

    public function test_daily_report_now_sends_and_stores_a_daily_snapshot(): void
    {
        $this->artisan('report:send-daily', ['--now' => true])
            ->expectsOutputToContain('[XCrawler]')
            ->assertSuccessful();

        self::assertCount(1, $this->delivered);
        self::assertSame('daily', ReportSnapshot::query()->firstOrFail()->getAttribute('report_type'));
    }

    public function test_daily_report_now_fails_when_the_channel_does_not_deliver(): void
    {
        $this->deliveryResult = false;

        $this->artisan('report:send-daily', ['--now' => true])
            ->expectsOutputToContain('The daily report was not delivered.')
            ->assertFailed();
    }

    public function test_daily_report_reports_a_duplicate_while_another_manual_send_holds_the_lock(): void
    {
        $lock = Cache::lock('javmeta:report:send:daily:manual', 300);
        self::assertTrue($lock->get());

        $this->artisan('report:send-daily', ['--now' => true])
            ->expectsOutputToContain('This daily report slot has already been handled.')
            ->assertSuccessful();

        self::assertSame([], $this->delivered);
        $lock->release();
    }
}
