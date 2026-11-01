<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Health;

use App\Services\Health\StartupHealthService;
use App\Services\Settings\SettingsService;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupHealthServiceTest extends TestCase
{
    public function test_primary_failure_blocks_while_secondary_failure_only_warns(): void
    {
        $fixture = StartupHealthTestFixture::bind([
            'postgres' => false,
            'sentry' => false,
        ]);

        $report = $fixture['health']->run('worker', 0);

        self::assertTrue($report['blocking']);
        self::assertFalse($report['primary_passed']);
        self::assertSame('fail', $this->row($report, 'postgres')['status']);
        self::assertSame('warn', $this->row($report, 'sentry')['status']);
        self::assertSame('secondary', $this->row($report, 'sentry')['class']);
    }

    public function test_elasticsearch_is_primary_for_web_and_secondary_for_worker_by_default(): void
    {
        $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);

        $web = $fixture['health']->run('web', 0, false);
        $worker = $fixture['health']->run('worker', 0);

        self::assertTrue($web['blocking']);
        self::assertSame('fail', $this->row($web, 'elasticsearch')['status']);
        self::assertFalse($worker['blocking']);
        self::assertSame('warn', $this->row($worker, 'elasticsearch')['status']);
    }

    public function test_settings_store_failure_uses_the_code_bootstrap_primary_list(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $store = new class extends \JOOservices\LaravelConfig\Testing\FakeConfigStore {
            public function group(string $group): array
            {
                throw new \RuntimeException('settings store unavailable');
            }
        };
        app()->instance(\JOOservices\LaravelConfig\Contracts\ConfigStore::class, $store);
        app()->forgetInstance(\App\Services\Settings\SettingsService::class);
        SettingsService::forgetDefaults();
        $health = app(StartupHealthService::class);

        $report = $health->run('web', 0, false);

        self::assertSame(['postgres', 'redis', 'mongo'], $report['primary']);
        self::assertTrue($report['enabled']);
        self::assertFalse($report['settings_loaded']);
        self::assertTrue($report['blocking']);
    }

    public function test_cached_settings_do_not_hide_an_unavailable_settings_store(): void
    {
        $fixture = StartupHealthTestFixture::bind();
        $store = new class extends \JOOservices\LaravelConfig\Testing\FakeConfigStore {
            public function refresh(): void
            {
                throw new \RuntimeException('settings store unavailable');
            }
        };
        $store->set('settings.health.startup.enabled', false, 'bool');
        app()->instance(\JOOservices\LaravelConfig\Contracts\ConfigStore::class, $store);
        app()->forgetInstance(\App\Services\Settings\SettingsService::class);
        SettingsService::forgetDefaults();
        app()->forgetInstance(StartupHealthService::class);

        $report = app(StartupHealthService::class)->run('worker', 0, false);

        self::assertFalse($report['settings_loaded']);
        self::assertTrue($report['enabled']);
        self::assertTrue($report['blocking']);
    }

    public function test_a_database_disable_switch_disables_the_gate_but_keeps_the_report(): void
    {
        $fixture = StartupHealthTestFixture::bind(['elasticsearch' => false]);
        $fixture['store']->set('settings.health.startup.enabled', false, 'bool');

        $report = $fixture['health']->run('web', 0, false);

        self::assertFalse($report['enabled']);
        self::assertFalse($report['primary_passed']);
        self::assertFalse($report['blocking']);
    }

    public function test_it_retries_primary_checks_until_a_dependency_recovers(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => [false, true]]);
        $time = 0.0;
        $health = new StartupHealthService(
            app(\App\Services\Dependencies\DependencyMonitor::class),
            app(\App\Services\Settings\SettingsService::class),
            app(\App\Observability\ObservabilityEmitter::class),
            static function (float $seconds) use (&$time): void {
                $time += $seconds;
            },
            static function () use (&$time): float {
                return $time;
            },
        );

        $report = $health->run('worker', 2, false);

        self::assertFalse($report['blocking']);
        self::assertSame(2, $fixture['probes']['postgres']->calls);
    }

    /** @param array<string, mixed> $report @return array<string, mixed> */
    private function row(array $report, string $service): array
    {
        foreach ($report['checks'] as $row) {
            if ($row['service'] === $service) {
                return $row;
            }
        }

        self::fail("Missing startup check for {$service}.");
    }
}
