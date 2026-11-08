<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use Illuminate\Support\Facades\Artisan;
use Tests\Support\Console\ScriptedCommand;
use Tests\TestCase;

/** crawl:tick and crawler:run-source only orchestrate other commands; the stand-ins script each step's exit code. */
final class CrawlerOrchestrationCommandsTest extends TestCase
{
    /** @var array<string, ScriptedCommand> */
    private array $steps = [];

    /** @param array<string, int> $exitCodes */
    private function scriptSteps(array $exitCodes = []): void
    {
        foreach (['crawler:sync-sources', 'crawler:reclaim', 'crawler:seed', 'crawler:feed-pool'] as $name) {
            $this->steps[$name] = new ScriptedCommand($name, $exitCodes[$name] ?? 0);
            Artisan::registerCommand($this->steps[$name]);
        }
    }

    /** @return list<string> the steps that ran, in order */
    private function ran(): array
    {
        return array_keys(array_filter($this->steps, static fn(ScriptedCommand $command): bool => $command->calls !== []));
    }

    public function test_tick_stops_with_a_failure_when_source_sync_fails(): void
    {
        $this->scriptSteps(['crawler:sync-sources' => 1]);

        $this->artisan('crawl:tick')->expectsOutputToContain('deprecated')->assertFailed();

        self::assertSame(['crawler:sync-sources'], $this->ran());
    }

    public function test_tick_stops_with_a_failure_when_reclaim_fails(): void
    {
        $this->scriptSteps(['crawler:reclaim' => 1]);

        $this->artisan('crawl:tick')->assertFailed();

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim'], $this->ran());
    }

    public function test_tick_returns_the_exit_code_of_the_seed_step(): void
    {
        $this->scriptSteps(['crawler:seed' => 1]);

        $this->artisan('crawl:tick', ['--limit' => 5])->assertFailed();

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim', 'crawler:seed'], $this->ran());
        self::assertSame('5', (string) $this->steps['crawler:seed']->calls[0]['limit']);
        self::assertArrayNotHasKey('source', $this->steps['crawler:seed']->calls[0]);
    }

    public function test_run_source_stops_when_source_sync_fails(): void
    {
        $this->scriptSteps(['crawler:sync-sources' => 2]);

        $this->artisan('crawler:run-source', ['slug' => 'javbus'])->assertExitCode(2);

        self::assertSame(['crawler:sync-sources'], $this->ran());
    }

    public function test_run_source_stops_when_reclaim_fails(): void
    {
        $this->scriptSteps(['crawler:reclaim' => 3]);

        $this->artisan('crawler:run-source', ['slug' => 'javbus'])->assertExitCode(3);

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim'], $this->ran());
    }

    public function test_run_source_returns_the_exit_code_of_the_seed_step_and_skips_the_feed_pool(): void
    {
        $this->scriptSteps(['crawler:seed' => 4]);

        $this->artisan('crawler:run-source', ['slug' => 'javbus', '--dispatch' => true])->assertExitCode(4);

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim', 'crawler:seed'], $this->ran());
    }

    public function test_run_source_seeds_one_source_and_then_feeds_the_pool_when_asked_to_dispatch(): void
    {
        $this->scriptSteps();

        $this->artisan('crawler:run-source', ['slug' => 'javbus', '--dispatch' => true, '--limit' => 7])->assertSuccessful();

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim', 'crawler:seed', 'crawler:feed-pool'], $this->ran());
        self::assertSame('javbus', $this->steps['crawler:seed']->calls[0]['source']);
        self::assertSame('7', (string) $this->steps['crawler:seed']->calls[0]['limit']);
        self::assertSame('7', (string) $this->steps['crawler:feed-pool']->calls[0]['limit']);
    }

    public function test_run_source_without_a_slug_seeds_every_source_and_does_not_feed_the_pool(): void
    {
        $this->scriptSteps();

        $this->artisan('crawler:run-source')->assertSuccessful();

        self::assertSame(['crawler:sync-sources', 'crawler:reclaim', 'crawler:seed'], $this->ran());
        self::assertArrayNotHasKey('source', $this->steps['crawler:seed']->calls[0]);
        self::assertSame('20', (string) $this->steps['crawler:seed']->calls[0]['limit']);
    }
}
