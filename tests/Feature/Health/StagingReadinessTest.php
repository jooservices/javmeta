<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Contracts\ReportChannel;
use App\Models\Movie;
use App\Models\Source;
use App\Services\Health\StagingReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class StagingReadinessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{name: string, status: string, message: string, action: ?string}> */
    private function byName(): array
    {
        $rows = [];
        foreach (app(StagingReadiness::class)->run(false)['checks'] as $check) {
            $rows[$check['name']] = $check;
        }

        return $rows;
    }

    public function test_the_crawl_checks_fail_with_a_cause_and_an_action_and_pass_when_things_are_fine(): void
    {
        config(['laravel-notifications.channels.telegram.bot_token' => '', 'javmeta_alerts.telegram.bot_token' => '']);
        $rows = $this->byName();
        $this->assertSame('fail', $rows['newest_movie']['status']);
        $this->assertNotNull($rows['newest_movie']['action']);
        $this->assertSame('fail', $rows['alert_channel']['status']);

        Movie::factory()->create(['first_seen_at' => now()->subMinutes(5)]);
        config(['laravel-notifications.channels.telegram.bot_token' => 'x', 'laravel-notifications.channels.telegram.chat_id' => '1']);
        $rows = $this->byName();
        $this->assertSame('pass', $rows['newest_movie']['status']);
        $this->assertNull($rows['newest_movie']['action']);
        $this->assertSame('pass', $rows['stuck_jobs']['status']);
        $this->assertSame('pass', $rows['alert_channel']['status']);

        Movie::query()->update(['first_seen_at' => now()->subHours(3)]);
        $this->assertSame('fail', $this->byName()['newest_movie']['status']);
    }

    public function test_source_freshness_respects_manifest_cycle_and_keeps_never_succeeded_sources_past_due(): void
    {
        Source::factory()->create(['slug' => 'eporner', 'last_success_at' => now()->subDays(3)]);
        Source::factory()->create(['slug' => 'warashi', 'last_success_at' => null]);

        $sources = $this->byName()['sources'];

        $this->assertSame('warn', $sources['status']);
        $this->assertStringContainsString('1/2 enabled sources are current or not yet due', $sources['message']);
        $this->assertStringContainsString('past due: warashi', $sources['message']);
        $this->assertStringContainsString('Not yet due under configured cycle: eporner', $sources['message']);
    }

    public function test_a_failing_run_is_stored_and_alerts_once_until_the_failures_change(): void
    {
        $history = storage_path('app/private/monitoring/readiness-history.jsonl');
        File::delete($history);
        $sent = [];
        $this->app->instance(ReportChannel::class, new class ($sent) implements ReportChannel {
            /** @param list<string> $sent */
            public function __construct(public array &$sent) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = $body;

                return true;
            }
        });

        $service = $this->app->make(StagingReadiness::class);
        $first = $service->run();
        $this->assertSame('fail', $first['status']);
        $this->assertCount(1, $sent);
        $service->run();
        $this->assertCount(1, $sent, 'the same failures do not alert twice');
        $this->assertCount(2, array_filter(explode("\n", (string) file_get_contents($history))));
        File::delete($history);
    }

    public function test_ready_transition_sends_recovery_for_previous_failing_checks(): void
    {
        $history = storage_path('app/private/monitoring/readiness-history.jsonl');
        File::ensureDirectoryExists(dirname($history));
        File::put($history, json_encode([
            'checked_at' => now()->subHour()->utc()->toIso8601String(),
            'status' => 'fail',
            'failing' => ['backup', 'scheduler'],
        ], JSON_UNESCAPED_SLASHES) . "\n");

        $sent = [];
        $this->app->instance(ReportChannel::class, new class ($sent) implements ReportChannel {
            /** @param list<array{string, string, array<string, mixed>}> $sent */
            public function __construct(public array &$sent) {}

            public function deliver(string $subject, string $body, array $context = []): bool
            {
                $this->sent[] = [$subject, $body, $context];

                return true;
            }
        });

        $service = $this->app->make(StagingReadiness::class);
        (new \ReflectionMethod(StagingReadiness::class, 'record'))->invoke($service, [
            'status' => 'pass',
            'checked_at' => now()->utc()->toIso8601String(),
            'checks' => [],
        ]);

        $this->assertCount(1, $sent);
        $this->assertSame('Staging readiness recovered', $sent[0][0]);
        $this->assertStringContainsString('backup, scheduler', $sent[0][1]);
        $this->assertSame(2, $sent[0][2]['resolved_checks']);
        $this->assertSame('resolved', $sent[0][2]['alert_state']);
        $this->assertSame('info', $sent[0][2]['severity']);
        File::delete($history);
    }

    public function test_the_command_exits_non_zero_on_a_fail_and_prints_json(): void
    {
        $this->artisan('staging:readiness', ['--json' => true, '--no-record' => true])->assertFailed();
    }
}
