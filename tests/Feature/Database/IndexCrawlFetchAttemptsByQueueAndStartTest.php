<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class IndexCrawlFetchAttemptsByQueueAndStartTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_migration_rebuilds_invalid_index_concurrently_without_a_transaction(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_index_crawl_fetch_attempts_by_queue_and_start.php');
        $this->assertFalse($migration->withinTransaction);

        DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
        DB::shouldReceive('selectOne')
            ->once()
            ->withArgs(static fn(string $sql, array $bindings): bool => str_contains($sql, 'i.indisvalid')
                && str_contains($sql, 'to_regclass(?)')
                && $bindings === ['crawl_fetch_attempts_queue_started_idx'])
            ->andReturn((object) ['invalid' => 1]);

        $statements = [];
        DB::shouldReceive('statement')->twice()->andReturnUsing(static function (string $sql) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });

        $migration->up();

        $this->assertSame([
            'DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_queue_started_idx',
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_queue_started_idx ON crawl_fetch_attempts (crawl_queue_id, started_at)',
        ], $statements);
    }

    public function test_postgres_migration_drops_the_index_concurrently_on_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_02_000001_index_crawl_fetch_attempts_by_queue_and_start.php');
        DB::shouldReceive('getDriverName')->once()->andReturn('pgsql');
        DB::shouldReceive('statement')
            ->once()
            ->with('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_queue_started_idx')
            ->andReturnTrue();

        $migration->down();
    }

    public function test_sqlite_migration_rollback_uses_the_schema_index_operation(): void
    {
        $index = 'crawl_fetch_attempts_queue_started_idx';
        $this->assertSame(1, DB::table('sqlite_master')->where('type', 'index')->where('name', $index)->count());

        $migration = require database_path('migrations/2026_11_02_000001_index_crawl_fetch_attempts_by_queue_and_start.php');
        $migration->down();

        $this->assertSame(0, DB::table('sqlite_master')->where('type', 'index')->where('name', $index)->count());
    }
}
