<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Health\StartupCommandGate;
use App\Services\Health\StartupHealthService;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Report\NodeSampleStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StartupHealthGateTest extends IntegrationTestCase
{
    public function test_a_closed_postgres_port_blocks_a_worker_startup_gate(): void
    {
        $connectionName = (string) config('database.default');
        $original = config("database.connections.{$connectionName}");
        $port = $this->closedLoopbackPort();
        config([
            "database.connections.{$connectionName}.host" => '127.0.0.1',
            "database.connections.{$connectionName}.port" => $port,
        ]);
        DB::purge($connectionName);

        try {
            $report = app(StartupHealthService::class)->run('worker', 0, false);

            self::assertTrue($report['blocking']);
            self::assertSame('fail', $this->checkStatus($report, 'postgres'));
            try {
                app(StartupCommandGate::class)->handle('crawler:reclaim');
                self::fail('A worker command must stop when PostgreSQL cannot be reached.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('postgres', $exception->getMessage());
            }
        } finally {
            config(["database.connections.{$connectionName}" => $original]);
            DB::purge($connectionName);
        }
    }

    public function test_AC2_node_sample_runs_and_stores_its_sample_when_elasticsearch_is_unreachable(): void
    {
        $node = 'startup-gate-' . bin2hex(random_bytes(6));
        $originalHost = config('elasticsearch.host');
        $originalNode = config('sentry.node');
        config([
            'elasticsearch.host' => 'http://127.0.0.1:' . $this->closedLoopbackPort(),
            'sentry.node' => $node,
        ]);

        try {
            $monitor = app(DependencyMonitor::class);
            foreach ([Dependency::Postgres, Dependency::Redis, Dependency::Mongo] as $dependency) {
                self::assertTrue($monitor->probeNow($dependency)->available, $dependency->value);
            }
            self::assertFalse($monitor->probeNow(Dependency::Elasticsearch)->available);

            app(StartupCommandGate::class)->handle('node:sample');
            self::assertSame(0, Artisan::call('node:sample'), Artisan::output());

            $samples = app(NodeSampleStore::class)->samples($node);
            self::assertCount(1, $samples);
            self::assertSame($node, $samples[0]['node']);
        } finally {
            config([
                'elasticsearch.host' => $originalHost,
                'sentry.node' => $originalNode,
            ]);
        }
    }

    public function test_AC2_the_same_command_is_refused_while_elasticsearch_is_down_when_it_is_classified_as_web(): void
    {
        $originalHost = config('elasticsearch.host');
        $originalRoles = config('javmeta_health.startup.command_roles');
        config([
            'elasticsearch.host' => 'http://127.0.0.1:' . $this->closedLoopbackPort(),
            'javmeta_health.startup.command_roles' => ['node:sample' => 'web'],
        ]);

        try {
            self::assertFalse(app(DependencyMonitor::class)->probeNow(Dependency::Elasticsearch)->available);

            try {
                app(StartupCommandGate::class)->handle('node:sample');
                self::fail('A command classified as web must be refused while Elasticsearch is down.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('elasticsearch', $exception->getMessage());
            }
        } finally {
            config([
                'elasticsearch.host' => $originalHost,
                'javmeta_health.startup.command_roles' => $originalRoles,
            ]);
        }
    }

    /** @param array<string, mixed> $report */
    private function checkStatus(array $report, string $service): string
    {
        foreach ($report['checks'] as $check) {
            if ($check['service'] === $service) {
                return (string) $check['status'];
            }
        }

        self::fail("Missing {$service} startup check.");
    }

    private function closedLoopbackPort(): int
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        self::assertIsString($address);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }
}
