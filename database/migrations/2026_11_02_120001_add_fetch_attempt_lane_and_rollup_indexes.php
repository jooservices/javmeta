<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->addLaneColumnOnPostgres();
            $this->rebuildInvalidStartedAtIndex();
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_started_at_idx ON crawl_fetch_attempts (started_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_runs_started_at_idx ON crawl_runs (started_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_first_seen_at_idx ON performers (first_seen_at)');
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS failed_jobs_failed_at_idx ON failed_jobs (failed_at)');

            return;
        }

        if (! Schema::hasColumn('crawl_fetch_attempts', 'lane')) {
            Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
                $table->string('lane', 32)->nullable();
            });
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

        if (Schema::hasColumn('crawl_fetch_attempts', 'lane')) {
            Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
                $table->dropColumn('lane');
            });
        }
    }

    private function addLaneColumnOnPostgres(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (Schema::hasColumn('crawl_fetch_attempts', 'lane')) {
                return;
            }

            try {
                DB::transaction(static function (): void {
                    DB::statement("SET LOCAL lock_timeout = '3s'");
                    DB::statement('ALTER TABLE crawl_fetch_attempts ADD COLUMN IF NOT EXISTS lane varchar(32) NULL');
                });

                return;
            } catch (QueryException $exception) {
                if (! $this->isLockTimeout($exception) || $attempt === 5) {
                    throw $exception;
                }

                usleep(1_000_000);
            }
        }
    }

    private function isLockTimeout(QueryException $exception): bool
    {
        return $exception->getCode() === '55P03' || ($exception->errorInfo[0] ?? null) === '55P03';
    }

    private function rebuildInvalidStartedAtIndex(): void
    {
        $existing = DB::selectOne(
            'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
            ['crawl_fetch_attempts_started_at_idx'],
        );

        if ((int) ($existing->invalid ?? 0) === 1) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_started_at_idx');
        }
    }
};
