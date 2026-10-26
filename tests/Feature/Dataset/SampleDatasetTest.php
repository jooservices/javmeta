<?php

declare(strict_types=1);

namespace Tests\Feature\Dataset;

use App\Models\Movie;
use App\Models\Performer;
use App\Services\Dataset\DatasetGuard;
use App\Services\Dataset\SampleDataset;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SampleDatasetTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_loads_the_promised_amounts_and_loading_twice_changes_nothing(): void
    {
        $this->artisan('dataset:load', ['name' => 'sample'])->assertSuccessful();
        $first = app(SampleDataset::class)->counts();
        $tables = fn(): array => [DB::table('movies')->count(), DB::table('performers')->count(), DB::table('movie_codes')->count(), DB::table('movie_performers')->count(), DB::table('movie_genres')->count(), DB::table('movie_observations')->count(), DB::table('performer_aliases')->count(), DB::table('genres')->count()];
        $before = $tables();

        $this->artisan('dataset:load', ['name' => 'sample'])->assertSuccessful();

        $this->assertSame($before, $tables());
        $this->assertSame($first, app(SampleDataset::class)->counts());
        $this->assertGreaterThanOrEqual(500, $first['movies']);
        $this->assertGreaterThanOrEqual(200, $first['performers']);
        $this->assertGreaterThanOrEqual(30, $first['genres']);
        $this->assertGreaterThanOrEqual(20, $first['makers']);
        $this->assertGreaterThanOrEqual(10, $first['series']);
    }

    public function test_it_contains_the_awkward_cases(): void
    {
        $this->artisan('dataset:load', ['name' => 'sample'])->assertSuccessful();

        $this->assertNotNull(Movie::query()->where('display_code', 'SMP-0001')->value('delisted_at'), 'a delisted movie');
        $this->assertSame(2, DB::table('movie_codes')->join('movies', 'movies.id', '=', 'movie_codes.movie_id')->where('movies.display_code', 'SMP-0005')->count(), 'two sources for one movie');
        $this->assertTrue(DB::table('performers')->where('source_slug', 'sample')->whereNull('bio_text')->exists(), 'missing bio fields');
        $this->assertTrue(DB::table('performers')->where('source_slug', 'sample')->whereNotNull('name_kanji')->exists(), 'non-Latin names');
        $this->assertTrue(DB::table('movies')->where('display_code', 'like', 'SMP-%')->whereNotNull('title_jp')->exists());
        $this->assertSame(3, DB::table('performer_aliases')->where('performer_id', DB::table('performers')->where('external_id', 'p0001')->value('id'))->count(), 'a performer with aliases');
    }

    public function test_wipe_removes_exactly_the_sample_and_leaves_other_rows(): void
    {
        $real = Movie::factory()->create(['display_code' => 'REAL-001']);
        $this->artisan('dataset:load', ['name' => 'sample'])->assertSuccessful();
        $this->artisan('dataset:wipe', ['name' => 'sample'])->assertSuccessful();

        $this->assertSame(['movies' => 0, 'performers' => 0, 'genres' => 0, 'makers' => 0, 'series' => 0], app(SampleDataset::class)->counts());
        $this->assertSame(1, DB::table('movies')->count());
        $this->assertNotNull(Movie::query()->find($real->id));
        foreach (['movie_codes', 'movie_performers', 'movie_genres', 'movie_observations', 'performer_aliases', 'performers', 'genres'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->artisan('dataset:load', ['name' => 'other'])->assertFailed();
    }
    public function test_wipe_preserves_an_unmarked_movie_with_a_sample_code(): void
    {
        $real = Movie::factory()->create(['display_code' => 'SMP-0042', 'code_normalized' => 'SMP042', 'title_en' => 'Real movie']);
        $this->artisan('dataset:wipe', ['name' => 'sample'])->assertSuccessful();
        $this->assertSame('Real movie', $real->fresh()->getAttribute('title_en'));
        $this->assertSame(0, app(SampleDataset::class)->counts()['movies']);
    }

    public function test_load_refuses_to_overwrite_unmarked_normalized_code_and_rolls_back(): void
    {
        $real = Movie::factory()->create(['display_code' => 'SMP-0042', 'code_normalized' => 'SMP042', 'title_en' => 'Real movie']);
        $this->artisan('dataset:load', ['name' => 'sample'])->expectsOutputToContain('collision')->assertFailed();
        $this->assertSame('Real movie', $real->fresh()->getAttribute('title_en'));
        $this->assertSame(1, DB::table('movies')->count());
        $this->assertSame(0, DB::table('sample_dataset_rows')->count());
        $this->assertSame(0, DB::table('performers')->count());
    }

    public function test_non_sqlite_testing_database_still_uses_the_allow_list_without_force(): void
    {
        config(['javmeta_dataset.allowed_databases' => ['javmeta_test']]);
        $connection = \Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('javmeta_test');
        DB::shouldReceive('connection')->once()->andReturn($connection);

        app(DatasetGuard::class)->check(false);
    }

    public function test_non_sqlite_testing_database_is_refused_when_not_allow_listed(): void
    {
        config(['javmeta_dataset.allowed_databases' => ['javmeta_test']]);
        $connection = \Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('foreign_database');
        DB::shouldReceive('connection')->once()->andReturn($connection);

        $this->expectException(\RuntimeException::class);
        app(DatasetGuard::class)->check(true);
    }

    public function test_both_commands_refuse_unapproved_effective_database_even_with_force(): void
    {
        $this->app->instance('env', 'staging');
        config(['javmeta_dataset.allowed_databases' => ['javmeta_uat', 'javmeta_test']]);
        foreach (['dataset:load', 'dataset:wipe'] as $command) {
            $this->artisan($command, ['name' => 'sample', '--force' => true])->expectsOutputToContain('not allow-listed')->assertFailed();
        }
        $this->assertSame(0, DB::table('movies')->count());
    }

    public function test_both_commands_require_force_on_an_allow_listed_database_outside_testing(): void
    {
        $this->app->instance('env', 'uat');
        config(['javmeta_dataset.allowed_databases' => [':memory:']]);
        foreach (['dataset:load', 'dataset:wipe'] as $command) {
            $this->artisan($command, ['name' => 'sample'])->expectsOutputToContain('requires --force')->assertFailed();
        }
        $this->artisan('dataset:load', ['name' => 'sample', '--force' => true])->assertSuccessful();
        $this->artisan('dataset:wipe', ['name' => 'sample', '--force' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('movies')->count());
    }

    public function test_unmarked_genre_and_performer_collisions_are_not_adopted(): void
    {
        $genre = DB::table('genres')->insertGetId(['label_normalized' => 'sample-01', 'label_raw' => 'Real genre']);
        $this->artisan('dataset:load', ['name' => 'sample'])->assertFailed();
        $this->artisan('dataset:wipe', ['name' => 'sample'])->assertSuccessful();
        $this->assertSame('Real genre', DB::table('genres')->where('id', $genre)->value('label_raw'));
        DB::table('genres')->where('id', $genre)->delete();
        $performer = Performer::factory()->create(['source_slug' => 'sample', 'external_id' => 'p0001', 'name_romaji' => 'Real performer']);
        $this->artisan('dataset:load', ['name' => 'sample'])->assertFailed();
        $this->artisan('dataset:wipe', ['name' => 'sample'])->assertSuccessful();
        $this->assertSame('Real performer', $performer->fresh()->getAttribute('name_romaji'));
    }

    public function test_ownership_migration_can_be_rolled_back_and_reapplied(): void
    {
        $migration = require database_path('migrations/2026_10_25_000004_create_sample_dataset_rows.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('sample_dataset_rows'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('sample_dataset_rows'));
    }
}
