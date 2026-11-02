<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use App\Services\Settings\SettingsService;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Symfony\Component\Console\Input\StringInput;
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
            'horizon:reconcile-workers' => '* * * * *',
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
        $this->assertSame(5, $scheduled['crawler:feed-pool']->repeatSeconds);
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

    public function test_feed_pool_schedule_polls_at_five_seconds_independent_of_the_current_database_interval(): void
    {
        $settings = new SettingsService(new FakeConfigStore());
        $this->app->instance(SettingsService::class, $settings);
        $previousEnvironment = app('env');
        app()->instance('env', 'local');
        try {
            foreach ([5, 60] as $interval) {
                $settings->set('queue.feed_interval_seconds', $interval);
                $this->app->instance(Schedule::class, new Schedule());
                ScheduleFacade::clearResolvedInstance(Schedule::class);
                require base_path('routes/console.php');

                $feed = collect(app(Schedule::class)->events())
                    ->first(static fn($event): bool => str_contains((string) $event->command, 'crawler:feed-pool'));

                $this->assertNotNull($feed, "Feed pool schedule was not registered for {$interval} seconds.");
                $this->assertSame(5, $feed->repeatSeconds);
                $this->assertSame('* * * * *', $feed->expression);
                $this->assertStringContainsString('--scheduled', (string) $feed->command);
            }
        } finally {
            app()->instance('env', $previousEnvironment);
        }
    }

    public function test_every_scheduled_command_string_is_accepted_by_the_artisan_kernel(): void
    {
        $events = $this->registeredEvents();
        $commands = app(Kernel::class)->all();
        $commandEvents = array_filter($events, static fn($event): bool => ! $event instanceof CallbackEvent);

        $this->assertNotEmpty($commandEvents);
        foreach ($commandEvents as $event) {
            $matchedCommand = preg_match('/artisan[\'\"]?\s+([^\s\'\"]+)(.*)$/', (string) $event->command, $matches);
            $this->assertSame(1, $matchedCommand, (string) $event->command);

            $commandName = $matches[1];
            $command = $commands[$commandName] ?? null;
            $this->assertInstanceOf(Command::class, $command, $commandName);

            $input = new StringInput(trim($matches[2]));
            $input->bind($command->getDefinition());
            $parameters = $this->parametersFrom($input, $command);
            $parameters['--help'] = true;

            $this->assertSame(0, Artisan::call($commandName, $parameters), $commandName);
        }
    }

    public function test_scheduled_command_failures_are_logged_but_successes_are_not(): void
    {
        $commandEvents = array_filter(
            $this->registeredEvents(),
            static fn($event): bool => ! $event instanceof CallbackEvent,
        );
        $expectedCommands = [];
        foreach ($commandEvents as $event) {
            preg_match('/artisan[\'"]?\s+([^\s\'"]+)/', (string) $event->command, $matches);
            $expectedCommands[] = $matches[1] ?? '';
        }
        $remainingCommands = $expectedCommands;

        Log::shouldReceive('error')
            ->times(count($commandEvents))
            ->withArgs(static function (string $message, array $context) use (&$remainingCommands): bool {
                $expectedCommand = array_shift($remainingCommands);

                return $message === 'Scheduled command failed.'
                    && $expectedCommand !== null
                    && ($context['command'] ?? null) === $expectedCommand;
            })
            ->andReturnNull();

        foreach ($commandEvents as $event) {
            $event->finish($this->app, 0);
            $event->finish($this->app, 1);
        }
    }

    /** @return list<\Illuminate\Console\Scheduling\Event> */
    private function registeredEvents(): array
    {
        $previousEnvironment = app('env');
        app()->instance('env', 'local');
        $this->app->instance(Schedule::class, new Schedule());
        ScheduleFacade::clearResolvedInstance(Schedule::class);

        try {
            require base_path('routes/console.php');
        } finally {
            app()->instance('env', $previousEnvironment);
        }

        return app(Schedule::class)->events();
    }

    /** @return array<string, mixed> */
    private function parametersFrom(StringInput $input, Command $command): array
    {
        $parameters = [];
        $definition = $command->getDefinition();

        foreach ($input->getArguments() as $name => $value) {
            if ($value !== $definition->getArgument($name)->getDefault()) {
                $parameters[$name] = $value;
            }
        }

        foreach ($input->getOptions() as $name => $value) {
            if ($value !== $definition->getOption($name)->getDefault()) {
                $parameters['--' . $name] = $value;
            }
        }

        return $parameters;
    }
}
