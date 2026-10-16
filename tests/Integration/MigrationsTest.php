<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

/**
 * The unit/feature suites migrate sqlite only. Production runs Postgres, so
 * every migration must apply, roll back and re-apply there.
 */
final class MigrationsTest extends BaseTestCase
{
    public function test_migrations_apply_roll_back_and_reapply_on_postgres(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        self::assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        foreach (['movies', 'movie_sources', 'movie_observations', 'performers', 'performer_sources', 'crawl_queue', 'sources'] as $table) {
            self::assertTrue(Schema::hasTable($table), "missing table {$table}");
        }

        self::assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]), Artisan::output());
        self::assertFalse(Schema::hasColumn('crawl_queue', 'error_code'));

        DB::table('crawl_queue')->insert([
            'source_slug' => 'legacy-source',
            'url' => 'https://legacy.test/item',
            'kind' => 'detail',
            'status' => 'failed',
            'attempts' => 3,
            'max_attempts' => 3,
            'last_error' => null,
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $legacyRow = DB::table('crawl_queue')->where('source_slug', 'legacy-source')->first();
        self::assertSame('legacy_unclassified', $legacyRow?->error_code);
        self::assertSame('Failure reason not recorded before JAVM-37.', $legacyRow?->last_error);
        self::assertNull($legacyRow?->last_failed_at);

        $failureMigration = require database_path('migrations/2026_10_16_000001_backfill_failed_reasons.php');
        $failureMigration->up();
        $legacyRowAfterRepeat = DB::table('crawl_queue')->where('source_slug', 'legacy-source')->first();
        self::assertSame('legacy_unclassified', $legacyRowAfterRepeat?->error_code);
        self::assertSame($legacyRow?->last_failed_at, $legacyRowAfterRepeat?->last_failed_at);

        try {
            DB::table('crawl_queue')->insert([
                'source_slug' => 'invalid-source',
                'url' => 'https://invalid.test/item',
                'kind' => 'detail',
                'status' => 'failed',
                'attempts' => 1,
                'max_attempts' => 3,
                'last_error' => null,
            ]);
            self::fail('PostgreSQL accepted a failed row without an error reason.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        self::assertSame(0, Artisan::call('migrate:reset', ['--force' => true]), Artisan::output());
        self::assertFalse(Schema::hasTable('movies'));

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        self::assertTrue(Schema::hasTable('movies'));
    }
}
