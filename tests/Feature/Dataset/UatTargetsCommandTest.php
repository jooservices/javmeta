<?php

declare(strict_types=1);

namespace Tests\Feature\Dataset;

use Illuminate\Support\Facades\DB;
use Closure;
use Tests\TestCase;

final class UatTargetsCommandTest extends TestCase
{
    private function targets(): void
    {
        $this->app->instance('env', 'uat');
        config([
            'app.env' => 'uat',
            'database.default' => 'pgsql',
            'database.connections.pgsql.url' => null,
            'database.connections.pgsql.host' => 'postgres',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'javmeta_uat',
            'database.connections.mongodb.database' => 'javmeta_uat',
            'mongodb.database' => 'javmeta_uat',
            'elasticsearch.movies_index' => 'javmeta_uat_movies',
            'elasticsearch.performers_index' => 'javmeta_uat_performers',
        ]);
        DB::purge('pgsql');
    }

    public function test_correct_targets_are_verified_without_opening_a_database_connection(): void
    {
        $this->targets();
        $this->artisan('ops:uat-targets')->expectsOutput('UAT_TARGETS_VERIFIED')->assertSuccessful();
        $this->assertInstanceOf(Closure::class, DB::connection()->getRawPdo());
    }

    public function test_each_wrong_effective_target_or_environment_is_refused(): void
    {
        foreach ([
            'app.env' => 'staging',
            'database.connections.pgsql.host' => 'other-server',
            'database.connections.pgsql.port' => '5433',
            'database.connections.pgsql.database' => 'javmeta',
            'database.connections.pgsql.url' => 'postgres://test:test@postgres:5432/javmeta',
            'database.connections.mongodb.database' => 'javmeta',
            'mongodb.database' => 'javmeta',
            'elasticsearch.movies_index' => 'javmeta_movies',
            'elasticsearch.performers_index' => 'javmeta_performers',
            'database.default' => 'sqlite',
        ] as $key => $value) {
            $this->targets();
            config([$key => $value]);
            DB::purge('pgsql');
            $this->artisan('ops:uat-targets')->assertFailed();
        }
    }
}
