<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\Dependencies\Probes\PostgresProbe;
use Illuminate\Support\Facades\DB;

final class PostgresProbeConnectionLimitTest extends IntegrationTestCase
{
    public function test_check_reuses_a_connection_when_the_database_role_has_one_slot(): void
    {
        $role = 'probe_' . bin2hex(random_bytes(6));
        $password = bin2hex(random_bytes(16));
        $admin = DB::connection('pgsql');
        $quotedPassword = $admin->getPdo()->quote($password);
        self::assertIsString($quotedPassword);
        $admin->statement(sprintf('CREATE ROLE "%s" LOGIN PASSWORD %s CONNECTION LIMIT 1', $role, $quotedPassword));

        $originalUsername = config('database.connections.pgsql.username');
        $originalPassword = config('database.connections.pgsql.password');
        config([
            'database.connections.pgsql.username' => $role,
            'database.connections.pgsql.password' => $password,
        ]);
        DB::purge('pgsql');

        try {
            DB::connection('pgsql')->select('SELECT 1');

            $result = (new PostgresProbe())->check();

            self::assertTrue($result->available, $result->message);
        } finally {
            DB::purge('pgsql');
            config([
                'database.connections.pgsql.username' => $originalUsername,
                'database.connections.pgsql.password' => $originalPassword,
            ]);
            DB::purge('pgsql');
            DB::connection('pgsql')->statement(sprintf('DROP ROLE IF EXISTS "%s"', $role));
        }
    }
}
