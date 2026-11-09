<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Movie;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

final class MakeMovieSourceArchiveIdNullableMigrationTest extends BaseTestCase
{
    use DatabaseMigrations;

    public function test_migration_can_be_reapplied_after_rollback_creates_placeholder_ids(): void
    {
        self::assertSame('pgsql', DB::connection()->getDriverName());

        $movie = Movie::factory()->create();
        DB::table('movie_sources')->insert([
            'movie_id' => $movie->id,
            'site_slug' => 'javdb',
            'mongo_id' => null,
            'source_url' => 'https://javdb.test/v/migration',
            'source_code' => 'SSIS-001',
            'crawled_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_11_06_000001_make_movie_source_archive_id_nullable.php');
        $migration->down();

        $placeholder = DB::table('movie_sources')->where('movie_id', $movie->id)->value('mongo_id');
        self::assertSame('unarchived:' . DB::table('movie_sources')->where('movie_id', $movie->id)->value('id'), $placeholder);

        $migration->up();

        self::assertNull(DB::table('movie_sources')->where('movie_id', $movie->id)->value('mongo_id'));
    }
}
