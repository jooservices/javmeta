<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SuggestEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-API-Key' => app(ApiKeyService::class)->create('suggest-test')->plaintext];
    }

    public function test_it_needs_a_key_and_a_valid_text(): void
    {
        $this->getJson('/api/v1/suggest?q=abc')->assertUnauthorized();
        $this->getJson('/api/v1/suggest?q=a', $this->headers())->assertStatus(400)->assertJsonPath('type', 'invalid_filter');
        $this->getJson('/api/v1/suggest?q=abc&limit=99', $this->headers())->assertStatus(400);
    }

    public function test_it_rejects_an_overlong_query_and_a_limit_below_one(): void
    {
        $headers = $this->headers();

        $this->getJson('/api/v1/suggest?q=' . str_repeat('a', 101), $headers)
            ->assertStatus(400)
            ->assertJsonPath('errors.q', ['2 to 100 characters']);
        $this->getJson('/api/v1/suggest?q=abc&limit=0', $headers)
            ->assertStatus(400)
            ->assertJsonPath('errors.limit', ['1 to 20']);
    }

    public function test_it_suggests_movies_performers_makers_and_series(): void
    {
        Movie::factory()->create(['display_code' => 'ABC-001', 'title_en' => 'First', 'maker' => 'Abacus', 'series' => 'Abc Series']);
        Movie::factory()->create(['display_code' => 'ZZZ-002', 'title_en' => 'Has abc inside', 'maker' => 'Other', 'series' => null]);
        DB::table('performers')->insert(['source_slug' => 's', 'external_id' => '1', 'name_romaji' => 'Abe Tester', 'crawled_at' => now()]);
        DB::table('performers')->insert(['source_slug' => 's', 'external_id' => '2', 'name_romaji' => 'Nobody', 'crawled_at' => now()]);

        $data = $this->getJson('/api/v1/suggest?q=abc', $this->headers())->assertOk()->json('data');
        $this->assertSame(['ABC-001', 'ZZZ-002'], array_column($data['movies'], 'code'));
        $this->assertSame([['name' => 'Abc Series', 'movie_count' => 1]], $data['series']);
        $this->assertSame([], $data['performers']);

        $data = $this->getJson('/api/v1/suggest?q=abe', $this->headers())->assertOk()->json('data');
        $this->assertSame(['Abe Tester'], array_column($data['performers'], 'name'));
        $this->assertSame([], $data['movies']);

        $g = \App\Models\Genre::factory()->create(['label_raw' => 'Abc Genre']);
        $movie = Movie::query()->where('display_code', 'ABC-001')->first();
        DB::table('movie_genres')->insert(['movie_id' => $movie->id, 'genre_id' => $g->id, 'source_slug' => 's', 'crawled_at' => now()]);
        $this->assertSame([['name' => 'Abc Genre', 'movie_count' => 1]], $this->getJson('/api/v1/suggest?q=abc', $this->headers())->json('data.genres'));

        $this->assertSame([['name' => 'Abacus', 'movie_count' => 1]], $this->getJson('/api/v1/suggest?q=abac', $this->headers())->json('data.makers'));
    }

    public function test_it_matches_performer_aliases_and_returns_the_performers_display_name(): void
    {
        $performerId = DB::table('performers')->insertGetId([
            'source_slug' => 'alias-source',
            'external_id' => 'alias-1',
            'name_kanji' => 'Alias Performer',
            'crawled_at' => now(),
        ]);
        DB::table('performer_aliases')->insert([
            'performer_id' => $performerId,
            'alias' => 'Hidden Stage Name',
            'kind' => 'stage_name',
            'source_slug' => 'alias-source',
            'source_performer_id' => $performerId,
        ]);

        $data = $this->getJson('/api/v1/suggest?q=stage+name', $this->headers())->assertOk()->json('data');

        $this->assertSame([['id' => $performerId, 'name' => 'Alias Performer']], $data['performers']);

        $kanaPerformerId = DB::table('performers')->insertGetId([
            'source_slug' => 'kana-source',
            'external_id' => 'kana-1',
            'name_romaji' => 'Kana Display Name',
            'name_kana' => 'Kana Search Name',
            'crawled_at' => now(),
        ]);
        $data = $this->getJson('/api/v1/suggest?q=kana+search', $this->headers())->assertOk()->json('data');
        $this->assertSame([['id' => $kanaPerformerId, 'name' => 'Kana Display Name']], $data['performers']);
    }

    public function test_it_falls_back_to_the_japanese_title_when_the_english_title_is_missing(): void
    {
        Movie::factory()->create([
            'display_code' => 'JP-001',
            'title_en' => null,
            'title_jp' => 'Japanese-only result',
            'maker' => null,
        ]);

        $data = $this->getJson('/api/v1/suggest?q=japanese-only', $this->headers())->assertOk()->json('data');

        $this->assertSame([['code' => 'JP-001', 'title' => 'Japanese-only result', 'maker' => null]], $data['movies']);
    }

    public function test_it_trims_queries_applies_limits_and_counts_matching_facets(): void
    {
        $older = Movie::factory()->create([
            'display_code' => 'RPT-001',
            'title_en' => 'Repeat Title One',
            'maker' => 'Repeat Maker',
            'series' => 'Repeat Series',
            'release_date' => '2025-01-01',
        ]);
        $newer = Movie::factory()->create([
            'display_code' => 'RPT-002',
            'title_en' => 'Repeat Title Two',
            'maker' => 'Repeat Maker',
            'series' => 'Repeat Series',
            'release_date' => '2026-01-01',
        ]);
        $genre = \App\Models\Genre::factory()->create(['label_raw' => 'Repeat Genre']);
        foreach ([$older, $newer] as $movie) {
            DB::table('movie_genres')->insert([
                'movie_id' => $movie->id,
                'genre_id' => $genre->id,
                'source_slug' => 'suggest-test',
                'crawled_at' => now(),
            ]);
        }

        $data = $this->getJson('/api/v1/suggest?q=+Repeat+&limit=1', $this->headers())->assertOk()->json('data');

        $this->assertSame([['code' => 'RPT-002', 'title' => 'Repeat Title Two', 'maker' => 'Repeat Maker']], $data['movies']);
        $this->assertSame([['name' => 'Repeat Genre', 'movie_count' => 2]], $data['genres']);
        $this->assertSame([['name' => 'Repeat Maker', 'movie_count' => 2]], $data['makers']);
        $this->assertSame([['name' => 'Repeat Series', 'movie_count' => 2]], $data['series']);
    }
}
