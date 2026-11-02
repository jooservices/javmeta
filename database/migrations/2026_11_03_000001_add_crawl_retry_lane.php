<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public $withinTransaction = false;

    private const INDEXES = [
        'crawl_queue_retry_due_idx' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_queue_retry_due_idx ON crawl_queue (kind, next_attempt_at, id) WHERE status = 'retry_pending'",
        'crawl_queue_failed_retry_import_idx' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_queue_failed_retry_import_idx ON crawl_queue (last_failed_at, id) WHERE status = 'failed'",
        'crawl_queue_retry_source_round_idx' => "CREATE INDEX CONCURRENTLY IF NOT EXISTS crawl_queue_retry_source_round_idx ON crawl_queue (source_slug, retry_round, next_attempt_at, id) WHERE status = 'retry_pending'",
    ];

    public function up(): void
    {
        $this->withLockTimeoutRetry(function (): void {
            if (! Schema::hasColumn('crawl_queue', 'retry_round')) {
                Schema::table('crawl_queue', function (Blueprint $table): void {
                    $table->smallInteger('retry_round')->default(0);
                });
            }
            if (! Schema::hasColumn('crawl_queue', 'retry_egress_label')) {
                Schema::table('crawl_queue', function (Blueprint $table): void {
                    $table->string('retry_egress_label', 64)->nullable();
                });
            }
        });

        if (! Schema::hasTable('crawl_retry_metrics')) {
            Schema::create('crawl_retry_metrics', function (Blueprint $table): void {
                $table->id();
                $table->text('source_slug');
                $table->smallInteger('round');
                $table->string('outcome', 32);
                $table->string('egress_label', 64)->default('default');
                $table->bigInteger('value')->default(0);
                $table->unique(['source_slug', 'round', 'outcome', 'egress_label'], 'crawl_retry_metrics_unique');
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->index(['kind', 'next_attempt_at', 'id'], 'crawl_queue_retry_due_idx');
                $table->index(['last_failed_at', 'id'], 'crawl_queue_failed_retry_import_idx');
                $table->index(['source_slug', 'retry_round', 'next_attempt_at', 'id'], 'crawl_queue_retry_source_round_idx');
            });

            return;
        }

        foreach (self::INDEXES as $name => $statement) {
            $this->createConcurrentIndex($name, $statement);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (array_keys(self::INDEXES) as $name) {
                $this->withLockTimeoutRetry(static fn(): bool => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $name));
            }
        } else {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->dropIndex('crawl_queue_retry_due_idx');
                $table->dropIndex('crawl_queue_failed_retry_import_idx');
                $table->dropIndex('crawl_queue_retry_source_round_idx');
            });
        }

        Schema::dropIfExists('crawl_retry_metrics');
        if (Schema::hasColumn('crawl_queue', 'retry_round')) {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->dropColumn('retry_round');
                $table->dropColumn('retry_egress_label');
            });
        }
    }

    private function createConcurrentIndex(string $name, string $statement): void
    {
        $existing = DB::selectOne(
            'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
            [$name],
        );

        if ((int) ($existing->invalid ?? 0) === 1) {
            $this->withLockTimeoutRetry(static fn(): bool => DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $name));
        }

        $this->withLockTimeoutRetry(static fn(): bool => DB::statement($statement));
    }

    private function withLockTimeoutRetry(callable $operation): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $operation();

            return;
        }

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            DB::statement("SET lock_timeout TO '5s'");
            try {
                $operation();

                return;
            } catch (\Throwable $error) {
                if (! $this->isLockTimeout($error) || $attempt === 5) {
                    throw $error;
                }
                usleep($attempt * 100_000);
            } finally {
                DB::statement('RESET lock_timeout');
            }
        }
    }

    private function isLockTimeout(Throwable $error): bool
    {
        $sqlState = (string) $error->getCode();

        return $sqlState === '55P03';
    }
};
