<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MovieFiltersTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        app(SampleDataset::class)->load();
        $this->headers = ['X-API-Key' => app(ApiKeyService::class)->create('filters', 60, ['movies'])->plaintext];
    }

    private function codes(string $query): array
    {
        return array_column($this->getJson('/api/v1/movies?per_page=100&' . $query, $this->headers)->assertOk()->json('data'), 'code');
    }

    public function test_actress_matches_a_name_part_an_alias_and_the_picker_id_matches_exactly(): void
    {
        $this->assertNotEmpty($this->codes('actress=Aiko'));
        $aliasMovies = $this->codes('actress=' . urlencode('Sample Alias One'));
        $this->assertNotEmpty($aliasMovies, 'an alias finds the performer');

        $id = DB::table('performers')->where('external_id', 'p0001')->value('id');
        $byId = $this->codes('actress_id=' . $id);
        $this->assertEqualsCanonicalizing($aliasMovies, $byId);

        $other = DB::table('performers')->where('external_id', 'p0002')->value('id');
        $both = $this->codes('actress_id=' . $id . ',' . $other);
        $this->assertTrue(count($both) <= count($byId), 'several performers narrow to the movies they share');
        $this->getJson('/api/v1/movies?actress_id=abc', $this->headers)->assertStatus(400);
    }

    public function test_genres_any_or_all_source_score_tier_and_flags(): void
    {
        $any = $this->codes('genre=' . urlencode('Sample Genre 001,Sample Genre 002'));
        $one = $this->codes('genre=' . urlencode('Sample Genre 001'));
        $all = $this->codes('genre=' . urlencode('Sample Genre 001,Sample Genre 002') . '&genre_all=1');
        $this->assertGreaterThanOrEqual(count($one), count($any));
        $this->assertLessThanOrEqual(count($one), count($all));

        $this->assertNotEmpty($this->codes('source=sample'));
        $this->assertSame([], $this->codes('source=nonexistent'));
        $scored = $this->getJson('/api/v1/movies?per_page=100&score_min=7', $this->headers)->json('data');
        $this->assertNotEmpty($scored);
        foreach ($scored as $movie) {
            $this->assertGreaterThanOrEqual(7, $movie['community_score']);
        }
        foreach ($this->getJson('/api/v1/movies?per_page=100&tier_min=3', $this->headers)->json('data') as $movie) {
            $this->assertSame(3, $movie['completeness_tier']);
        }
        $this->assertSame([], $this->codes('has_cover=1'), 'the sample has no covers');
        $this->assertNotEmpty($this->codes('has_cover=0'));
        $this->assertNotEmpty($this->codes('has_performers=1'));
        $this->assertNotEmpty($this->codes('has_magnet=1'));
    }

    public function test_a_quoted_value_matches_exactly_while_a_bare_value_keeps_the_substring_search(): void
    {
        // What a facet tick sends is quoted, so the movies found equal the count shown beside the value.
        $name = (string) DB::table('genres')->whereIn('id', DB::table('movie_genres')->select('genre_id'))->orderBy('id')->value('label_raw');
        $prefix = substr($name, 0, -1);

        $bare = $this->codes('genre=' . urlencode($prefix));
        $exact = $this->codes('genre=' . urlencode('"' . $name . '"'));

        $this->assertNotEmpty($exact);
        $this->assertGreaterThan(count($exact), count($bare), 'the bare prefix also finds the neighbouring genres');
        $this->assertSame([], $this->codes('genre=' . urlencode('"' . $prefix . '"')), 'an exact prefix is not a genre');
        $this->assertSame([], $this->codes('genre=' . urlencode('"' . strtoupper($name) . '"')), 'a facet tick is the value exactly as listed');

        // A comma inside the quotes belongs to the value and is not a list separator.
        $commaGenre = DB::table('genres')->insertGetId(['label_normalized' => 'foo-inc', 'label_raw' => 'Foo, Inc.']);
        $movieId = (int) DB::table('movies')->value('id');
        DB::table('movie_genres')->insert(['movie_id' => $movieId, 'genre_id' => $commaGenre, 'source_slug' => 'sample', 'crawled_at' => now()]);
        $this->assertContains((string) DB::table('movies')->where('id', $movieId)->value('display_code'), $this->codes('genre=' . urlencode('"Foo, Inc."')));

        $maker = (string) DB::table('movies')->whereNotNull('maker')->value('maker');
        $this->assertNotEmpty($this->codes('maker=' . urlencode('"' . $maker . '"')));
        $this->assertSame([], $this->codes('maker=' . urlencode('"' . substr($maker, 0, -1) . '"')));
    }

    public function test_facets_count_inside_the_current_result_and_respect_the_filters(): void
    {
        $all = $this->getJson('/api/v1/movies/facets', $this->headers)->assertOk()->json('data');
        $this->assertSame(520, $all['total']);
        $this->assertNotEmpty($all['genres']);
        $this->assertNotEmpty($all['makers']);
        $this->assertNotEmpty($all['performers']);
        $this->assertSame('sample', $all['sources'][0]['name']);

        $narrow = $this->getJson('/api/v1/movies/facets?maker=' . urlencode('Sample Maker 03'), $this->headers)->assertOk()->json('data');
        $this->assertLessThan($all['total'], $narrow['total']);
        $this->assertSame(['Sample Maker 03'], array_column($narrow['makers'], 'name'));
        $this->getJson('/api/v1/movies/facets')->assertUnauthorized();
        $this->getJson('/api/v1/movies/NOPE-999', $this->headers)->assertNotFound();
    }

    public function test_the_text_search_still_finds_a_movie_through_one_of_its_performers(): void
    {
        $name = DB::table('performers')->where('external_id', 'p0005')->value('name_romaji');
        $codes = $this->codes('q=' . urlencode((string) $name));

        $this->assertNotEmpty($codes);
    }
}
