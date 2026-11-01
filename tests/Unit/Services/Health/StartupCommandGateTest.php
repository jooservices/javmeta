<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Console\Listeners\StartupCommandListener;
use App\Observability\ObservabilityEmitter;
use App\Services\Health\StartupCommandGate;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Events\CommandStarting;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use RuntimeException;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupCommandGateTest extends TestCase
{
    public function test_diagnostic_commands_are_exempt_from_the_gate(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        $gate->handle('health:startup');
        $gate->handle('settings:show');

        self::assertSame(0, $fixture['probes']['postgres']->calls);
    }

    public function test_batch_command_fails_fast_and_an_explicit_denylist_overrides_the_allowlist(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));
        config([
            'javmeta_health.startup.command_allowlist' => ['crawler:*'],
            'javmeta_health.startup.command_denylist' => ['crawler:reclaim'],
        ]);

        try {
            $gate->handle('crawler:reclaim');
            self::fail('A failing primary dependency must block a denied batch command.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Startup health blocked', $exception->getMessage());
            self::assertStringContainsString('postgres', $exception->getMessage());
        }
    }

    public function test_command_starting_listener_applies_database_allowlist_before_gating(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $fixture['store']->set('settings.health.startup.command_allowlist', 'crawler:reclaim', 'string');
        $listener = new StartupCommandListener(
            app(SettingsService::class),
            new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class)),
        );

        $listener->handle(new CommandStarting('crawler:reclaim', new ArrayInput([]), new BufferedOutput()));

        self::assertSame(0, $fixture['probes']['postgres']->calls);
    }

    public function test_command_starting_listener_blocks_an_unavailable_worker_command(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $listener = new StartupCommandListener(
            app(SettingsService::class),
            new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class)),
        );

        try {
            $listener->handle(new CommandStarting('crawler:reclaim', new ArrayInput([]), new BufferedOutput()));
            self::fail('The command listener must block a batch command when PostgreSQL is unavailable.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('postgres', $exception->getMessage());
        }
    }

    public function test_failed_settings_read_restores_code_allowlist_before_gating(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        self::assertTrue(app(SettingsService::class)->apply());
        config(['javmeta_health.startup.command_allowlist' => ['crawler:*']]);
        $store = Mockery::mock(ConfigStore::class);
        $store->shouldReceive('group')->once()->with(SettingsService::GROUP)->andThrow(new RuntimeException('store down'));
        $listener = new StartupCommandListener(
            new SettingsService($store),
            new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class)),
        );

        try {
            $listener->handle(new CommandStarting('crawler:reclaim', new ArrayInput([]), new BufferedOutput()));
            self::fail('A failed settings read must restore the fail-closed default allowlist.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('postgres', $exception->getMessage());
        }

        self::assertGreaterThan(0, $fixture['probes']['postgres']->calls);
        self::assertNotSame(['crawler:*'], config('javmeta_health.startup.command_allowlist'));
    }

    public function test_backup_status_uses_worker_dependencies_and_does_not_block_on_elasticsearch(): void
    {
        $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        $gate->handle('javmeta:backup-status');

        self::assertSame(0, $fixture['probes']['elasticsearch']->calls);
    }
}
