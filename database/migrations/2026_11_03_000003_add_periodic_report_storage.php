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
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '3s'");
            try {
                DB::statement('ALTER TABLE crawl_fetch_attempts ADD COLUMN IF NOT EXISTS kind varchar(64) NULL');
                DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_fetch_attempts_kind_started_idx ON crawl_fetch_attempts (kind, started_at)');
                DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS movies_crawled_at_report_idx ON movies (crawled_at)');
                DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_first_seen_report_idx ON performers (first_seen_at)');
                $this->backfillRecentKinds();
            } finally {
                DB::statement('RESET lock_timeout');
            }
        } else {
            Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
                $table->string('kind', 64)->nullable();
                $table->index(['kind', 'started_at'], 'crawl_fetch_attempts_kind_started_idx');
            });
            Schema::table('movies', static function (Blueprint $table): void {
                $table->index('crawled_at', 'movies_crawled_at_report_idx');
            });
            Schema::table('performers', static function (Blueprint $table): void {
                $table->index('first_seen_at', 'performers_first_seen_report_idx');
            });
        }

        Schema::create('report_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('scheduled_for')->nullable()->unique();
            $table->string('result', 16);
            $table->string('channel', 32)->default('telegram');
            $table->text('rendered_text');
            $table->json('raw_numbers');
            $table->timestampsTz();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_snapshots');

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SET lock_timeout = '3s'");
            try {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_fetch_attempts_kind_started_idx');
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS movies_crawled_at_report_idx');
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS performers_first_seen_report_idx');
                DB::statement('ALTER TABLE crawl_fetch_attempts DROP COLUMN IF EXISTS kind');
            } finally {
                DB::statement('RESET lock_timeout');
            }

            return;
        }

        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->dropIndex('crawl_fetch_attempts_kind_started_idx');
            $table->dropColumn('kind');
        });
        Schema::table('movies', static function (Blueprint $table): void {
            $table->dropIndex('movies_crawled_at_report_idx');
        });
        Schema::table('performers', static function (Blueprint $table): void {
            $table->dropIndex('performers_first_seen_report_idx');
        });
    }

    private function backfillRecentKinds(): void
    {
        $cutoff = now()->subDays(7);
        DB::table('crawl_fetch_attempts')
            ->whereNull('kind')
            ->whereNotNull('crawl_queue_id')
            ->where('created_at', '>=', $cutoff)
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                if ($rows->isEmpty()) {
                    return;
                }

                DB::update(
                    "UPDATE crawl_fetch_attempts AS attempts SET kind = queue.kind FROM crawl_queue AS queue WHERE attempts.crawl_queue_id = queue.id AND attempts.id BETWEEN ? AND ? AND attempts.kind IS NULL AND attempts.created_at >= CURRENT_TIMESTAMP - INTERVAL '7 days'",
                    [(int) $rows->first()->id, (int) $rows->last()->id],
                );
            });
    }
};
