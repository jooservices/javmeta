<?php

declare(strict_types=1);

namespace Tests\Feature\Dataset;

use App\Models\Movie;
use App\Services\Dataset\SampleDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
