<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FetchAttemptTest extends TestCase
{
    public function test_postgres_migration_indexes_round_trip_and_report(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());
        $migration = require database_path('migrations/2026_10_25_000004_create_crawl_fetch_attempts.php');
        $migration->down();
        $migration->up();
        try {
            $indexes = Schema::getIndexes('crawl_fetch_attempts');
            self::assertContains(['source_slug', 'created_at'], array_column($indexes, 'columns'));
            self::assertContains(['created_at'], array_column($indexes, 'columns'));
            DB::table('crawl_fetch_attempts')->insert([
                'created_at' => now(), 'source_slug' => 'sample', 'url_hash' => hash('sha256', 'https://example.test'),
                'method' => 'http', 'started_at' => now(), 'duration_ms' => 17, 'http_status' => 403,
                'cf_mitigated' => 'challenge', 'outcome' => 'error', 'error' => 'challenge detected',
            ]);
            $this->artisan('crawler:fetch-report sample')->expectsTable(['Method', 'Outcome', 'Count'], [['http', 'error', 1]])
                ->expectsTable(['Error', 'Count'], [['challenge detected', 1]])->assertSuccessful();
            self::assertSame(403, DB::table('crawl_fetch_attempts')->value('http_status'));
            $migration->down();
            self::assertFalse(Schema::hasTable('crawl_fetch_attempts'));
            $migration->up();
            self::assertTrue(Schema::hasTable('crawl_fetch_attempts'));
        } finally {
            // Direct migration checks must not leave an untracked table for later migrate:fresh tests.
            $migration->down();
        }
    }
}
