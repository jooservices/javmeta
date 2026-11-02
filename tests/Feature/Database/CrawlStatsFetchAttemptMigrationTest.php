<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CrawlStatsFetchAttemptMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_migration_sets_lock_timeout_and_rebuilds_invalid_started_index(): void
    {
        $migration = require database_path('migrations/2026_11_02_120001_add_fetch_attempt_lane_and_rollup_indexes.php');
        self::assertFalse($migration->withinTransaction);
        Schema::table('crawl_fetch_attempts', static function (Blueprint $table): void {
            $table->dropColumn('lane');
        });
        $schema = Schema::getFacadeRoot();

        $database = DB::partialMock();
        $database->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        Schema::swap($schema);
        $database->shouldReceive('transaction')->once()->withArgs(static function (Closure $callback): bool {
            $callback();

            return true;
        })->andReturn(true);
        $database->shouldReceive('selectOne')
            ->once()
            ->withArgs(static fn(string $sql, array $bindings): bool => str_contains($sql, 'i.indisvalid')
                && str_contains($sql, 'to_regclass(?)')
                && $bindings === ['crawl_fetch_attempts_started_at_idx'])
            ->andReturn((object) ['invalid' => 1]);

        $statements = [];
        $database->shouldReceive('statement')->times(7)->andReturnUsing(static function (string $sql) use (&$statements): bool {
            $statements[] = $sql;

            return true;
        });

        $migration->up();

        self::assertSame("SET LOCAL lock_timeout = '3s'", $statements[0]);
        self::assertSame('ALTER TABLE crawl_fetch_attempts ADD COLUMN IF NOT EXISTS lane varchar(32) NULL', $statements[1]);
        self::assertSame('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_started_at_idx', $statements[2]);
        self::assertSame(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_started_at_idx ON crawl_fetch_attempts (started_at)',
            $statements[3],
        );
        self::assertCount(7, $statements);
    }

    public function test_sqlite_migration_rolls_back_and_reapplies_the_lane_column(): void
    {
        $migration = require database_path('migrations/2026_11_02_120001_add_fetch_attempt_lane_and_rollup_indexes.php');

        self::assertTrue(Schema::hasColumn('crawl_fetch_attempts', 'lane'));
        $migration->down();
        self::assertFalse(Schema::hasColumn('crawl_fetch_attempts', 'lane'));
        $migration->up();
        self::assertTrue(Schema::hasColumn('crawl_fetch_attempts', 'lane'));
        $migration->up();
        $indexNames = array_column(Schema::getIndexes('crawl_fetch_attempts'), 'name');
        self::assertContains('crawl_fetch_attempts_started_at_idx', $indexNames);
        self::assertSame(1, count(array_filter($indexNames, static fn(string $name): bool => $name === 'crawl_fetch_attempts_started_at_idx')));
    }
}
