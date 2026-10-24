<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Contracts\ReportChannel;
use App\Models\Movie;
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

    public function test_a_failing_run_is_stored_and_alerts_once_until_the_failures_change(): void
    {
        $history = storage_path('app/private/monitoring/readiness-history.jsonl');
        File::delete($history);
        $sent = [];
        $this->app->instance(ReportChannel::class, new class($sent) implements ReportChannel {
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

    public function test_the_command_exits_non_zero_on_a_fail_and_prints_json(): void
    {
        $this->artisan('staging:readiness', ['--json' => true, '--no-record' => true])->assertFailed();
    }
}
