<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Console\Listeners\StartupCommandListener;
use App\Observability\ObservabilityEmitter;
use App\Services\Health\StartupCommandGate;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use RuntimeException;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupCommandGateTest extends TestCase
{
    public function test_AC1_node_sample_is_worker_and_checks_only_worker_primaries(): void
    {
        $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        $gate->handle('node:sample');

        self::assertSame('worker', config('javmeta_health.startup.command_roles')['node:sample'] ?? null);
        foreach (['postgres', 'redis', 'mongo'] as $dependency) {
            self::assertGreaterThan(0, $fixture['probes'][$dependency]->calls, $dependency);
        }
        self::assertSame(0, $fixture['probes']['elasticsearch']->calls);
    }

    public function test_AC3_unclassified_command_is_rejected_with_its_name(): void
    {
        $fixture = StartupHealthTestFixture::bind();
        config(['javmeta_health.startup.command_roles' => []]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        $gate->handle('');
        self::assertSame(0, $fixture['probes']['postgres']->calls);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ops:unclassified must be classified');

        $gate->handle('ops:unclassified');
    }

    public function test_AC3_every_registered_artisan_command_is_allowlisted_or_classified(): void
    {
        $allowlist = (array) config('javmeta_health.startup.command_allowlist', []);
        $roles = (array) config('javmeta_health.startup.command_roles', []);
        $unclassified = array_values(array_filter(
            array_keys(Artisan::all()),
            static function (string $command) use ($allowlist, $roles): bool {
                foreach ($allowlist as $pattern) {
                    if (is_string($pattern) && Str::is(strtolower($pattern), $command)) {
                        return false;
                    }
                }

                foreach ($roles as $pattern => $role) {
                    if (is_string($pattern) && is_string($role) && Str::is(strtolower($pattern), $command)) {
                        return false;
                    }
                }

                return true;
            },
        ));

        self::assertSame([], $unclassified, 'Unclassified Artisan commands: ' . implode(', ', $unclassified));
        self::assertSame([], array_diff(array_values($roles), ['web', 'worker', 'scheduler']));
    }

    public function test_AC2_node_sample_is_not_blocked_by_the_gate_during_an_elasticsearch_outage(): void
    {
        $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        $gate->handle('node:sample');

        self::assertSame(0, $fixture['probes']['elasticsearch']->calls);
    }

    public function test_a_blocked_worker_command_still_stops_when_ops_emission_throws(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        config([
            'openobserve.enabled' => true,
            'openobserve.url' => 'http://openobserve.test',
            'openobserve.stream_logs' => 'xcrawler_logs',
        ]);
        Http::fake(['*' => Http::response([], 500)]);
        Log::shouldReceive('sharedContext')->andReturn(['node' => 'test-node', 'egress_ip' => null]);
        Log::shouldReceive('channel')->with('single')->andThrow(new RuntimeException('telemetry unavailable'));
        $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

        try {
            $gate->handle('crawler:reclaim');
            self::fail('A worker command must remain blocked when PostgreSQL is unavailable.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Startup health blocked', $exception->getMessage());
            self::assertStringContainsString('postgres', $exception->getMessage());
        }
    }

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

    public function test_command_starting_listener_refreshes_database_policy_before_reading_it(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $store = Mockery::mock(ConfigStore::class);
        $store->shouldReceive('refresh')->once()->ordered();
        $store->shouldReceive('group')->once()->with(SettingsService::GROUP)->ordered()->andReturn([
            'health.startup.command_allowlist' => 'crawler:reclaim',
        ]);
        $listener = new StartupCommandListener(
            new SettingsService($store, app(\App\Services\Settings\JsonShapeValidator::class), app(\App\Services\Api\ResponseCacheEpoch::class)),
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
        $store->shouldReceive('refresh')->once()->andThrow(new RuntimeException('store down'));
        $listener = new StartupCommandListener(
            new SettingsService($store, app(\App\Services\Settings\JsonShapeValidator::class), app(\App\Services\Api\ResponseCacheEpoch::class)),
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

    public function test_observability_publish_commands_use_worker_dependencies(): void
    {
        foreach (['obs:publish-metrics', 'javmeta:obs-publish-metrics'] as $command) {
            $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
            $gate = new StartupCommandGate($fixture['health'], app(ObservabilityEmitter::class));

            $gate->handle($command);

            self::assertSame(0, $fixture['probes']['elasticsearch']->calls, $command);
        }
    }
}
