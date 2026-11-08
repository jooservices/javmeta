<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class RetryLaneMigrationTest extends IntegrationTestCase
{
    public function test_additive_retry_migration_is_repeatable_and_builds_valid_postgres_indexes(): void
    {
        self::assertTrue(Schema::hasColumn('crawl_queue', 'retry_round'));
        self::assertTrue(Schema::hasColumn('crawl_queue', 'retry_egress_label'));
        self::assertTrue(Schema::hasTable('crawl_retry_metrics'));

        $migration = require database_path('migrations/2026_11_03_000001_add_crawl_retry_lane.php');
        self::assertFalse($migration->withinTransaction);
        $migration->up();

        $indexes = DB::select("SELECT c.relname AS index_name, i.indisvalid AS valid FROM pg_class c JOIN pg_index i ON i.indexrelid = c.oid WHERE c.relname IN ('crawl_queue_retry_due_idx', 'crawl_queue_failed_retry_import_idx', 'crawl_queue_retry_source_round_idx') ORDER BY c.relname");
        self::assertCount(3, $indexes);
        foreach ($indexes as $index) {
            self::assertTrue($index->valid, "{$index->index_name} should be valid");
        }
    }
}
