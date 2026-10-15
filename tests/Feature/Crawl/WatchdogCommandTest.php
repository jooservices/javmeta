<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Console\Commands\Crawler\WatchdogCommand;
use App\Events\WorkerHeartbeatStale;
use App\Services\Crawl\WorkerHeartbeat;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WatchdogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_watchdog_command_is_registered_with_legacy_alias(): void
    {
        $commands = app(Kernel::class)->all();
        $command = $commands['crawler:watchdog'];

        $this->assertInstanceOf(WatchdogCommand::class, $command);
        $this->assertArrayHasKey('javmeta:watchdog', $commands);
        $this->assertSame($command, $commands['javmeta:watchdog']);
    }

    public function test_watchdog_dispatches_stale_heartbeat_event(): void
    {
        Event::fake([WorkerHeartbeatStale::class]);
        config(['javmeta_alerts.watchdog.heartbeat_stale_seconds' => 60]);
        cache()->forget(WorkerHeartbeat::CACHE_KEY);

        $this->artisan('crawler:watchdog')->assertSuccessful();

        Event::assertDispatched(WorkerHeartbeatStale::class);
    }
}
