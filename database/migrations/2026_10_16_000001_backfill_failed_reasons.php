<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const CHECK_NAME = 'crawl_queue_failed_reason_check';

    private const LEGACY_MESSAGE = 'Failure reason not recorded before JAVM-37.';

    public $withinTransaction = false;

    public function up(): void
    {
        $addErrorCode = ! Schema::hasColumn('crawl_queue', 'error_code');
        $addFailedAt = ! Schema::hasColumn('crawl_queue', 'last_failed_at');

        if ($addErrorCode || $addFailedAt) {
            Schema::table('crawl_queue', function (Blueprint $table) use ($addErrorCode, $addFailedAt): void {
                if ($addErrorCode) {
                    $table->string('error_code', 64)->nullable();
                }

                if ($addFailedAt) {
                    $table->timestampTz('last_failed_at')->nullable();
                }
            });
        }

        DB::table('crawl_queue')
            ->where('status', 'failed')
            ->where(function ($query): void {
                $query->whereNull('error_code')
                    ->orWhereNull('last_error');
            })
            ->update([
                'error_code' => DB::raw("COALESCE(error_code, 'legacy_unclassified')"),
                'last_error' => DB::raw("COALESCE(last_error, '" . self::LEGACY_MESSAGE . "')"),
            ]);

        if (DB::getDriverName() === 'pgsql') {
            if (! $this->constraintExists()) {
                DB::statement("ALTER TABLE crawl_queue ADD CONSTRAINT " . self::CHECK_NAME . " CHECK (status <> 'failed' OR (error_code IS NOT NULL AND last_error IS NOT NULL AND (last_failed_at IS NOT NULL OR error_code = 'legacy_unclassified'))) NOT VALID");
            }

            DB::statement('ALTER TABLE crawl_queue VALIDATE CONSTRAINT ' . self::CHECK_NAME);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && $this->constraintExists()) {
            DB::statement('ALTER TABLE crawl_queue DROP CONSTRAINT ' . self::CHECK_NAME);
        }

        $dropErrorCode = Schema::hasColumn('crawl_queue', 'error_code');
        $dropFailedAt = Schema::hasColumn('crawl_queue', 'last_failed_at');

        if ($dropErrorCode || $dropFailedAt) {
            Schema::table('crawl_queue', function (Blueprint $table) use ($dropErrorCode, $dropFailedAt): void {
                $columns = [];
                if ($dropErrorCode) {
                    $columns[] = 'error_code';
                }
                if ($dropFailedAt) {
                    $columns[] = 'last_failed_at';
                }
                $table->dropColumn($columns);
            });
        }
    }

    private function constraintExists(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conname = ? AND conrelid = to_regclass(?)',
            [self::CHECK_NAME, 'crawl_queue'],
        ) !== null;
    }
};
