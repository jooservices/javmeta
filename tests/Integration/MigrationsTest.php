<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;
use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationTemplateService;

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
        self::assertTrue(Schema::hasTable('notification_templates'));
        self::assertTrue(Schema::hasTable('notification_template_revisions'));
        self::assertSame(count(app(NotificationRegistry::class)->all()), app(NotificationTemplateService::class)->count());
        app(NotificationTemplateService::class)->markSent('follow.match');
        self::assertSame(1, (int) DB::table('notification_templates')->where('key', 'follow.match')->value('sent_count'));
        $this->leaveInvalidPendingKindIndex();
        $migration = require database_path('migrations/2026_11_02_000002_add_crawl_queue_pending_kind_index.php');
        $migration->up();
        $this->assertPendingKindIndex();
        foreach (['movies', 'movie_sources', 'movie_observations', 'performers', 'performer_sources', 'performer_identity_events', 'performer_identity_reviews', 'performer_identity_event_movie_links', 'crawl_queue', 'sources'] as $table) {
            self::assertTrue(Schema::hasTable($table), "missing table {$table}");
        }

        self::assertTrue(Schema::hasTable('performer_name_keys'));
        self::assertTrue(Schema::hasTable('performer_name_grams'));
        foreach (['egress_ip', 'node', 'egress_kind'] as $column) {
            self::assertTrue(Schema::hasColumn('crawl_fetch_attempts', $column), "missing crawl fetch attempt column {$column}");
        }

        // Roll back every migration from the failed reasons backfill (JAVM-37) onwards: new migrations extend the range, so count the files.
        $steps = count(array_filter(
            scandir(database_path('migrations')) ?: [],
            static fn(string $file): bool => str_ends_with($file, '.php') && $file >= '2026_10_16_000001_backfill_failed_reasons.php',
        ));
        self::assertSame(0, Artisan::call('migrate:rollback', ['--step' => $steps, '--force' => true]), Artisan::output());
        self::assertFalse(Schema::hasColumn('movies', 'recrawl_attempts'));
        self::assertFalse(Schema::hasColumn('movies', 'recrawl_status'));
        self::assertFalse(Schema::hasColumn('crawl_queue', 'is_recrawl'));
        self::assertFalse(Schema::hasColumn('crawl_queue', 'started_at'));
        foreach (['egress_ip', 'node', 'egress_kind'] as $column) {
            self::assertFalse(Schema::hasColumn('crawl_fetch_attempts', $column), "crawl fetch attempt column {$column} survived rollback");
        }
        self::assertFalse(Schema::hasColumn('performers', 'retired_date'));
        self::assertFalse(Schema::hasColumn('crawl_queue', 'error_code'));
        self::assertFalse(Schema::hasColumn('performers', 'canonical_performer_id'));
        self::assertFalse(Schema::hasColumn('performer_sources', 'source_performer_id'));
        self::assertFalse(Schema::hasTable('performer_identity_events'));
        self::assertFalse(Schema::hasTable('performer_identity_reviews'));
        self::assertFalse(Schema::hasTable('performer_identity_event_movie_links'));
        self::assertFalse(Schema::hasTable('performer_name_keys'));
        self::assertFalse(Schema::hasTable('performer_name_grams'));
        self::assertFalse(Schema::hasTable('notification_templates'));
        self::assertFalse(Schema::hasTable('notification_template_revisions'));
        self::assertNull(DB::selectOne('SELECT to_regclass(?) AS index_name', ['crawl_queue_pending_kind_id_idx'])?->index_name);

        DB::table('crawl_queue')->insert([
            'source_slug' => 'legacy-source',
            'url' => 'https://legacy.test/item',
            'kind' => 'detail',
            'status' => 'failed',
            'attempts' => 3,
            'max_attempts' => 3,
            'last_error' => null,
        ]);
        DB::table('crawl_queue')->insert([
            'source_slug' => 'legacy-claimed-source',
            'url' => 'https://legacy.test/claimed-item',
            'kind' => 'detail',
            'status' => 'claimed',
            'attempts' => 1,
            'max_attempts' => 3,
            'claimed_at' => '2026-10-19 10:00:00+00',
        ]);

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        $this->assertPendingKindIndex();
        $legacyRow = DB::table('crawl_queue')->where('source_slug', 'legacy-source')->first();
        self::assertSame('legacy_unclassified', $legacyRow?->error_code);
        self::assertSame('Failure reason not recorded before JAVM-37.', $legacyRow?->last_error);
        self::assertNull($legacyRow?->last_failed_at);
        $legacyClaimedRow = DB::table('crawl_queue')->where('source_slug', 'legacy-claimed-source')->first();
        self::assertSame($legacyClaimedRow?->claimed_at, $legacyClaimedRow?->started_at);
        $constraintValidation = DB::selectOne("SELECT CASE WHEN convalidated THEN 1 ELSE 0 END AS is_validated FROM pg_constraint WHERE conname = 'crawl_queue_failed_reason_check'");
        self::assertSame(1, (int) $constraintValidation?->is_validated);

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
        self::assertNull(DB::selectOne('SELECT to_regclass(?) AS index_name', ['crawl_queue_pending_kind_id_idx'])?->index_name);

        self::assertSame(0, Artisan::call('migrate', ['--force' => true]), Artisan::output());
        self::assertTrue(Schema::hasTable('movies'));
        self::assertTrue(Schema::hasTable('notification_templates'));
        self::assertSame(count(app(NotificationRegistry::class)->all()), app(NotificationTemplateService::class)->count());
        $this->assertPendingKindIndex();
        $this->verifyPeriodicReportMigration();
    }

    private function assertPendingKindIndex(): void
    {
        $index = DB::selectOne(
            "SELECT CASE WHEN i.indisvalid THEN 1 ELSE 0 END AS is_valid, pg_get_expr(i.indpred, i.indrelid) AS predicate, i.indexrelid::text AS index_oid FROM pg_index AS i WHERE i.indexrelid = to_regclass(?)",
            ['crawl_queue_pending_kind_id_idx'],
        );

        self::assertNotNull($index, 'The concurrent claim index must exist.');
        self::assertSame(1, (int) $index->is_valid);
        self::assertStringContainsString("status = 'pending'", (string) $index->predicate);
        $indexDefinition = DB::selectOne("SELECT pg_get_indexdef(to_regclass('crawl_queue_pending_kind_id_idx')) AS definition");
        self::assertStringContainsString('(kind, id)', (string) $indexDefinition?->definition);

        $migration = require database_path('migrations/2026_11_02_000002_add_crawl_queue_pending_kind_index.php');
        $migration->up();
        $indexAfterRepeat = DB::selectOne(
            'SELECT i.indexrelid::text AS index_oid FROM pg_index AS i WHERE i.indexrelid = to_regclass(?)',
            ['crawl_queue_pending_kind_id_idx'],
        );
        self::assertSame($index->index_oid, $indexAfterRepeat?->index_oid, 'A valid pre-existing index must remain untouched.');
    }

    private function verifyPeriodicReportMigration(): void
    {
        $queueIds = [];
        foreach (['recent-report-row', 'old-report-row'] as $source) {
            $queueIds[$source] = DB::table('crawl_queue')->insertGetId([
                'source_slug' => $source,
                'url' => 'https://' . $source . '.test/item',
                'kind' => 'performer_detail',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
        }

        foreach (['recent-report-row' => now()->subDays(2), 'old-report-row' => now()->subDays(9)] as $source => $createdAt) {
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => $createdAt,
                'source_slug' => $source,
                'crawl_queue_id' => $queueIds[$source],
                'url_hash' => hash('sha256', $source),
                'method' => 'GET',
                'started_at' => $createdAt,
                'duration_ms' => 100,
                'outcome' => 'ok',
            ]);
        }

        $migration = require database_path('migrations/2026_11_03_000002_add_periodic_report_storage.php');
        $migration->down();
        $migration->up();

        self::assertSame('performer_detail', DB::table('crawl_fetch_attempts')->where('source_slug', 'recent-report-row')->value('kind'));
        self::assertNull(DB::table('crawl_fetch_attempts')->where('source_slug', 'old-report-row')->value('kind'));
        self::assertTrue(Schema::hasTable('report_snapshots'));
        self::assertTrue(Schema::hasColumn('crawl_fetch_attempts', 'kind'));

        foreach (['crawl_fetch_attempts_kind_started_idx', 'movies_crawled_at_report_idx', 'performers_first_seen_report_idx'] as $name) {
            $index = DB::selectOne('SELECT i.indisvalid AS is_valid FROM pg_index AS i WHERE i.indexrelid = to_regclass(?)', [$name]);
            self::assertNotNull($index, "Missing PostgreSQL report index {$name}.");
            self::assertTrue($index->is_valid, "PostgreSQL report index {$name} must be valid.");
        }
    }

    private function leaveInvalidPendingKindIndex(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS crawl_queue_pending_kind_id_idx');
        DB::table('crawl_queue')->insert([
            [
                'source_slug' => 'duplicate-source',
                'url' => 'https://duplicate.test/one',
                'kind' => 'listing',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ],
            [
                'source_slug' => 'duplicate-source',
                'url' => 'https://duplicate.test/two',
                'kind' => 'listing',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ],
        ]);

        try {
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY crawl_queue_pending_kind_id_idx ON crawl_queue (source_slug)');
            self::fail('The duplicate source slugs must leave an invalid concurrent index.');
        } catch (QueryException) {
            // PostgreSQL keeps an invalid index after a concurrent unique build finds duplicates.
        }

        $invalid = DB::selectOne(
            'SELECT CASE WHEN i.indisvalid THEN 1 ELSE 0 END AS is_valid FROM pg_index AS i WHERE i.indexrelid = to_regclass(?)',
            ['crawl_queue_pending_kind_id_idx'],
        );
        self::assertNotNull($invalid, 'PostgreSQL must retain the failed concurrent index.');
        self::assertSame(0, (int) $invalid->is_valid);
    }
}
