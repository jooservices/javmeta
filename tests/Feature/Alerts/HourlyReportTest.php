<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Contracts\ReportChannel;
use App\Models\Movie;
use App\Models\Performer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class HourlyReportTest extends TestCase
{
    use RefreshDatabase;

    private string $state;

    /** @var list<array{subject: string, body: string}> */
    private array $sent = [];

    private bool $deliver = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = sys_get_temp_dir() . '/javmeta-report-' . uniqid('', true) . '.json';
        config()->set('javmeta_alerts.hourly_report.state_path', $this->state);
        $this->sent = [];
        $this->deliver = true;
        $test = $this;
        $this->app->instance(ReportChannel::class, new class ($test) implements ReportChannel {
            public function __construct(private readonly HourlyReportTest $test) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                return $this->test->record($subject, $body);
            }
        });
    }

    protected function tearDown(): void
    {
        File::delete($this->state);
        parent::tearDown();
    }

    public function record(string $subject, string $body): bool
    {
        if (! $this->deliver) {
            return false;
        }
        $this->sent[] = ['subject' => $subject, 'body' => $body];

        return true;
    }

    public function test_first_report_has_totals_and_no_change(): void
    {
        Movie::factory()->count(3)->create();
        Performer::factory()->count(2)->create();

        $this->artisan('javmeta:hourly-report')->assertSuccessful();

        $this->assertCount(1, $this->sent);
        $this->assertStringContainsString('Movies: 3', $this->sent[0]['body']);
        $this->assertStringContainsString('Performers: 2', $this->sent[0]['body']);
        $this->assertStringContainsString('First report', $this->sent[0]['body']);
        $this->assertFileExists($this->state);
    }

    public function test_next_report_shows_the_increase_since_the_previous_one(): void
    {
        Movie::factory()->count(3)->create();
        Performer::factory()->count(2)->create();
        $this->artisan('javmeta:hourly-report')->assertSuccessful();

        Movie::factory()->count(5)->create();
        Performer::factory()->count(1)->create();
        $this->artisan('javmeta:hourly-report')->assertSuccessful();

        $this->assertCount(2, $this->sent);
        $this->assertStringContainsString('Movies: 8 (+5 in ', $this->sent[1]['body']);
        $this->assertStringContainsString('Performers: 3 (+1)', $this->sent[1]['body']);
    }

    public function test_no_change_is_reported_as_plus_zero(): void
    {
        Movie::factory()->count(2)->create();
        $this->artisan('javmeta:hourly-report')->assertSuccessful();
        $this->artisan('javmeta:hourly-report')->assertSuccessful();

        $this->assertStringContainsString('Movies: 2 (+0 in ', $this->sent[1]['body']);
        $this->assertStringContainsString('Performers: 0 (+0)', $this->sent[1]['body']);
    }

    public function test_a_failed_send_keeps_the_old_values_so_the_next_hour_reports_the_full_increase(): void
    {
        Movie::factory()->count(2)->create();
        $this->artisan('javmeta:hourly-report')->assertSuccessful();
        $before = (string) file_get_contents($this->state);

        Movie::factory()->count(4)->create();
        $this->deliver = false;
        $this->artisan('javmeta:hourly-report')->assertFailed();
        $this->assertSame($before, (string) file_get_contents($this->state));

        $this->deliver = true;
        $this->artisan('javmeta:hourly-report')->assertSuccessful();
        $this->assertStringContainsString('Movies: 6 (+4 in ', $this->sent[1]['body']);
    }

    public function test_dry_run_prints_the_message_and_sends_nothing(): void
    {
        Movie::factory()->count(1)->create();

        $this->artisan('javmeta:hourly-report', ['--dry-run' => true])
            ->expectsOutputToContain('Movies: 1')
            ->assertSuccessful();

        $this->assertSame([], $this->sent);
        $this->assertFileDoesNotExist($this->state);
    }
}
