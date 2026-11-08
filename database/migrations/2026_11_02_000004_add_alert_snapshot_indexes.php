<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        $this->createIndex(
            table: 'crawl_fetch_attempts',
            name: 'crawl_fetch_attempts_started_source_idx',
            columns: ['started_at', 'source_slug'],
            sql: 'CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_started_source_idx ON crawl_fetch_attempts (started_at, source_slug)',
        );
        $this->createIndex(
            table: 'crawl_queue',
            name: 'crawl_queue_due_source_idx',
            columns: ['status', 'next_attempt_at', 'source_slug'],
            sql: "CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_queue_due_source_idx ON crawl_queue (next_attempt_at, source_slug) WHERE status = 'pending'",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_started_source_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_queue_due_source_idx');

            return;
        }

        Schema::table('crawl_queue', static function (Blueprint $table): void {
            $table->dropIndex('crawl_queue_due_source_idx');
        });
        Schema::table('crawl_fetch_attempts', static function (Blueprint $table): void {
            $table->dropIndex('crawl_fetch_attempts_started_source_idx');
        });
    }

    /** @param list<string> $columns */
    private function createIndex(string $table, string $name, array $columns, string $sql): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table($table, static function (Blueprint $blueprint) use ($columns, $name): void {
                $blueprint->index($columns, $name);
            });

            return;
        }

        $existing = DB::selectOne(
            'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
            [$name],
        );
        if ((int) ($existing->invalid ?? 0) === 1) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $name);
        }

        DB::statement($sql);
    }
};
