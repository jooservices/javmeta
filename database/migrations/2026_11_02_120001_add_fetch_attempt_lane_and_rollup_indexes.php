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
        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->string('lane', 32)->nullable();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_started_at_idx ON crawl_fetch_attempts (started_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_runs_started_at_idx ON crawl_runs (started_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_first_seen_at_idx ON performers (first_seen_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS failed_jobs_failed_at_idx ON failed_jobs (failed_at)');

            return;
        }

        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->index('started_at', 'crawl_fetch_attempts_started_at_idx');
        });
        Schema::table('crawl_runs', function (Blueprint $table): void {
            $table->index('started_at', 'crawl_runs_started_at_idx');
        });
        Schema::table('performers', function (Blueprint $table): void {
            $table->index('first_seen_at', 'performers_first_seen_at_idx');
        });
        Schema::table('failed_jobs', function (Blueprint $table): void {
            $table->index('failed_at', 'failed_jobs_failed_at_idx');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_started_at_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_runs_started_at_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS performers_first_seen_at_idx');
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS failed_jobs_failed_at_idx');
        } else {
            Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
                $table->dropIndex('crawl_fetch_attempts_started_at_idx');
            });
            Schema::table('crawl_runs', function (Blueprint $table): void {
                $table->dropIndex('crawl_runs_started_at_idx');
            });
            Schema::table('performers', function (Blueprint $table): void {
                $table->dropIndex('performers_first_seen_at_idx');
            });
            Schema::table('failed_jobs', function (Blueprint $table): void {
                $table->dropIndex('failed_jobs_failed_at_idx');
            });
        }

        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->dropColumn('lane');
        });
    }
};
