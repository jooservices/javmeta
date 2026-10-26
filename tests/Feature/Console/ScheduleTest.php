<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

final class ScheduleTest extends TestCase
{
    public function test_crawler_and_observability_schedule_has_the_expected_commands_and_frequencies(): void
    {
        $previousEnvironment = app('env');
        app()->instance('env', 'local');
        try {
            require base_path('routes/console.php');
        } finally {
            app()->instance('env', $previousEnvironment);
        }

        $scheduled = [];
        $heartbeatEvents = [];
        $events = app(Schedule::class)->events();
        foreach ($events as $event) {
            if ($event instanceof CallbackEvent) {
                $heartbeatEvents[] = $event;
                continue;
            }

            $matchedCommand = preg_match('/artisan[\'"]?\s+[\'"]?([^\s\'"]+)/', (string) $event->command, $matches);
            $this->assertSame(1, $matchedCommand);
            $key = $matches[1] . (str_contains((string) $event->command, '--status-only') ? ' --status-only' : '');
            $scheduled[$key] = $event;
        }

        $actualFrequencies = [];
        foreach ($scheduled as $command => $event) {
            $actualFrequencies[$command] = $event->expression;
        }
        ksort($actualFrequencies);

        $expectedFrequencies = [
            'ai:warm' => '*/15 * * * *',
            'crawler:feed-pool' => '* * * * *',
            'crawler:prune-fetch-attempts' => '0 0 * * *',
            'crawler:reclaim' => '* * * * *',
            'crawler:recrawl-incomplete' => '0 * * * *',
            'crawler:seed' => '* * * * *',
            'crawler:source-health' => '0 4 * * *',
            'crawler:sync-sources' => '*/15 * * * *',
            'crawler:watchdog' => '*/5 * * * *',
            'follows:notify' => '*/5 * * * *',
            'javmeta:backup-status' => '* * * * *',
            'javmeta:hourly-report' => '0 * * * *',
            'library:scan' => '0 * * * *',
            'obs:publish-metrics' => '* * * * *',
            'perf:warm' => '* * * * *',
            'perf:warm --status-only' => '* * * * *',
            'search:backfill-embeddings' => '0 * * * *',
            'staging:readiness' => '0 * * * *',
            'telegram:poll' => '* * * * *',
        ];

        $this->assertCount(count($expectedFrequencies) + 1, $events);
        $this->assertCount(1, $heartbeatEvents);
        $this->assertSame('javmeta:scheduler-heartbeat', $heartbeatEvents[0]->description);
        $this->assertSame('* * * * *', $heartbeatEvents[0]->expression);
        $this->assertSame($expectedFrequencies, $actualFrequencies);
        $this->assertTrue($scheduled['crawler:prune-fetch-attempts']->withoutOverlapping);
        $this->assertTrue($scheduled['crawler:feed-pool']->withoutOverlapping);
        $this->assertTrue($scheduled['crawler:recrawl-incomplete']->withoutOverlapping);
        $this->assertTrue($scheduled['javmeta:hourly-report']->withoutOverlapping);
        $this->assertTrue($scheduled['search:backfill-embeddings']->withoutOverlapping);
        $this->assertTrue($scheduled['staging:readiness']->withoutOverlapping);
        $this->assertTrue($scheduled['perf:warm']->withoutOverlapping);
        $this->assertSame(10, $scheduled['perf:warm --status-only']->repeatSeconds);
        foreach ($events as $event) {
            $this->assertFalse($event->onOneServer);
        }
    }
}
