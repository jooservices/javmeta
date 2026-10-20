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
            $scheduled[$matches[1]] = $event;
        }

        $actualFrequencies = [];
        foreach ($scheduled as $command => $event) {
            $actualFrequencies[$command] = $event->expression;
        }
        ksort($actualFrequencies);

        $expectedFrequencies = [
            'crawler:feed-pool' => '* * * * *',
            'crawler:reclaim' => '* * * * *',
            'crawler:recrawl-incomplete' => '0 * * * *',
            'crawler:seed' => '* * * * *',
            'crawler:sync-sources' => '*/15 * * * *',
            'crawler:watchdog' => '*/5 * * * *',
            'javmeta:backup-status' => '* * * * *',
            'obs:publish-metrics' => '* * * * *',
            'search:backfill-embeddings' => '0 * * * *',
        ];

        $this->assertCount(count($expectedFrequencies) + 1, $events);
        $this->assertCount(1, $heartbeatEvents);
        $this->assertSame('javmeta:scheduler-heartbeat', $heartbeatEvents[0]->description);
        $this->assertSame('* * * * *', $heartbeatEvents[0]->expression);
        $this->assertSame($expectedFrequencies, $actualFrequencies);
        $this->assertTrue($scheduled['crawler:feed-pool']->withoutOverlapping);
        $this->assertTrue($scheduled['crawler:recrawl-incomplete']->withoutOverlapping);
        $this->assertTrue($scheduled['search:backfill-embeddings']->withoutOverlapping);
        foreach ($events as $event) {
            $this->assertFalse($event->onOneServer);
        }
    }
}
