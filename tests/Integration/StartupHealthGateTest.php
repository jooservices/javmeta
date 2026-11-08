<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Health\StartupCommandGate;
use App\Services\Health\StartupHealthService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StartupHealthGateTest extends IntegrationTestCase
{
    public function test_a_closed_postgres_port_blocks_a_worker_startup_gate(): void
    {
        $connectionName = (string) config('database.default');
        $original = config("database.connections.{$connectionName}");
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($listener, $errorMessage);
        $address = stream_socket_get_name($listener, false);
        fclose($listener);
        self::assertIsString($address);
        $port = (int) substr($address, (int) strrpos($address, ':') + 1);
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
}
