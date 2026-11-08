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
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
                $table->index(['crawl_queue_id', 'started_at'], 'crawl_fetch_attempts_queue_started_idx');
            });

            return;
        }

        $existing = DB::selectOne(
            'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
            ['crawl_fetch_attempts_queue_started_idx'],
        );

        if ((int) ($existing->invalid ?? 0) === 1) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_queue_started_idx');
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_queue_started_idx ON crawl_fetch_attempts (crawl_queue_id, started_at)');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_queue_started_idx');

            return;
        }

        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->dropIndex('crawl_fetch_attempts_queue_started_idx');
        });
    }
};
