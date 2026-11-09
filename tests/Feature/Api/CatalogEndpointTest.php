<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Movie;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CatalogEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-API-Key' => app(ApiKeyService::class)->create('catalog-test', 60, ['catalog'])->plaintext];
    }

    public function test_it_needs_an_api_key_and_rejects_an_unknown_catalog(): void
    {
        $this->getJson('/api/v1/meta/catalog/makers')->assertUnauthorized();
        $this->getJson('/api/v1/meta/catalog/nonsense', $this->headers())->assertStatus(400)->assertJsonPath('type', 'invalid_filter');
        $this->getJson('/api/v1/meta/catalog/makers?per_page=999', $this->headers())->assertStatus(400);
        $this->getJson('/api/v1/meta/catalog/makers?sort=banana', $this->headers())->assertStatus(400);
    }

    public function test_makers_series_and_labels_are_counted_sorted_and_searchable(): void
    {
        Movie::factory()->count(3)->create(['maker' => 'Alpha', 'series' => 'S1', 'label' => 'L1']);
        Movie::factory()->count(1)->create(['maker' => 'Beta', 'series' => null, 'label' => null]);
        Movie::factory()->count(2)->create(['maker' => '', 'series' => 'S2', 'label' => null]);

        $makers = $this->getJson('/api/v1/meta/catalog/makers', $this->headers())->assertOk();
        $this->assertSame([['name' => 'Alpha', 'movie_count' => 3], ['name' => 'Beta', 'movie_count' => 1]], $makers->json('data'));
        $this->assertSame(2, $makers->json('meta.pagination.total'));

        $byName = $this->getJson('/api/v1/meta/catalog/makers?sort=name', $this->headers())->json('data.0.name');
        $this->assertSame('Alpha', $byName);
        $this->assertSame([['name' => 'Beta', 'movie_count' => 1]], $this->getJson('/api/v1/meta/catalog/makers?q=bet', $this->headers())->json('data'));
        $this->assertSame([['name' => 'S1', 'movie_count' => 3], ['name' => 'S2', 'movie_count' => 2]], $this->getJson('/api/v1/meta/catalog/series', $this->headers())->json('data'));
        $this->assertSame([['name' => 'L1', 'movie_count' => 3]], $this->getJson('/api/v1/meta/catalog/labels', $this->headers())->json('data'));
    }

    public function test_catalog_cache_key_is_deterministic_and_differs_for_different_params(): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        Movie::factory()->create(['maker' => 'Alpha']);

        $first = $this->getJson('/api/v1/meta/catalog/makers', $this->headers())->assertOk()->json('data');
        $key = 'javmeta:catalog:' . hash('sha256', json_encode(['makers', '', 'movie_count', 50, 1, false, 0, 'all'], JSON_THROW_ON_ERROR));
        $this->assertSame($first, \Illuminate\Support\Facades\Cache::get($key)['items']);
        $this->assertSame($first, $this->getJson('/api/v1/meta/catalog/makers', $this->headers())->assertOk()->json('data'));

        $sorted = $this->getJson('/api/v1/meta/catalog/makers?sort=name', $this->headers())->assertOk()->json('data');
        $sortedKey = 'javmeta:catalog:' . hash('sha256', json_encode(['makers', '', 'name', 50, 1, false, 0, 'all'], JSON_THROW_ON_ERROR));
        $this->assertNotSame($key, $sortedKey);
        $this->assertSame($sorted, \Illuminate\Support\Facades\Cache::get($sortedKey)['items']);
        $this->assertSame($first, \Illuminate\Support\Facades\Cache::get($key)['items']);
    }

    public function test_pages_split_the_list(): void
    {
        foreach (range(1, 5) as $i) {
            Movie::factory()->create(['maker' => 'Maker ' . $i]);
        }

        $page2 = $this->getJson('/api/v1/meta/catalog/makers?sort=name&per_page=2&page=2', $this->headers())->assertOk();
        $this->assertSame(['Maker 3', 'Maker 4'], array_column($page2->json('data'), 'name'));
        $this->assertSame(3, $page2->json('meta.pagination.last_page'));
    }

    public function test_genres_count_distinct_movies_and_years_count_by_release_year(): void
    {
        $drama = Genre::factory()->create(['label_raw' => 'Drama', 'label_normalized' => 'drama']);
        $movies = Movie::factory()->count(2)->create(['release_date' => '2025-03-01']);
        Movie::factory()->create(['release_date' => '2024-01-01']);
        foreach ($movies as $movie) {
            DB::table('movie_genres')->insert(['movie_id' => $movie->id, 'genre_id' => $drama->id, 'source_slug' => 'a', 'crawled_at' => now()]);
            DB::table('movie_genres')->insert(['movie_id' => $movie->id, 'genre_id' => $drama->id, 'source_slug' => 'b', 'crawled_at' => now()]);
        }

        $this->assertSame([['name' => 'Drama', 'movie_count' => 2]], $this->getJson('/api/v1/meta/catalog/genres', $this->headers())->json('data'));
        $this->assertSame([['name' => '2025', 'movie_count' => 2], ['name' => '2024', 'movie_count' => 1]], $this->getJson('/api/v1/meta/catalog/years', $this->headers())->json('data'));
    }

    public function test_year_catalog_search_keeps_only_the_typed_year(): void
    {
        Movie::factory()->create(['release_date' => '2025-03-01']);
        Movie::factory()->create(['release_date' => '2024-01-01']);

        $response = $this->getJson('/api/v1/meta/catalog/years?q=2025', $this->headers())->assertOk();

        $this->assertSame([['name' => '2025', 'movie_count' => 1]], $response->json('data'));
        $this->assertSame(1, $response->json('meta.pagination.total'));
    }

    public function test_min_count_hides_the_long_tail_and_reports_how_many_are_hidden(): void
    {
        Movie::factory()->count(3)->create(['maker' => 'Alpha']);
        Movie::factory()->count(1)->create(['maker' => 'Beta']);
        Movie::factory()->count(2)->create(['maker' => 'Gamma']);

        $response = $this->getJson('/api/v1/meta/catalog/makers?min_count=3', $this->headers())->assertOk();

        $this->assertSame([['name' => 'Alpha', 'movie_count' => 3]], $response->json('data'));
        $this->assertSame(1, $response->json('meta.pagination.total'));
        $this->assertSame(2, $response->json('meta.pagination.hidden_below_min'));
        $this->assertSame(3, $response->json('meta.pagination.min_count'));
        $all = $this->getJson('/api/v1/meta/catalog/makers', $this->headers())->assertOk();
        $this->assertSame(3, $all->json('meta.pagination.total'));
        $this->assertSame(0, $all->json('meta.pagination.hidden_below_min'));
        $this->getJson('/api/v1/meta/catalog/makers?min_count=-1', $this->headers())->assertStatus(400);
    }

    public function test_script_index_keeps_one_writing_system_and_combines_with_min_count(): void
    {
        Movie::factory()->count(3)->create(['maker' => 'Alpha']);
        Movie::factory()->count(3)->create(['maker' => 'ハイビジョン']);
        Movie::factory()->count(1)->create(['maker' => '中出し']);
        Movie::factory()->count(3)->create(['maker' => '巨乳']);
        Movie::factory()->count(1)->create(['maker' => '美女']);
        Movie::factory()->count(3)->create(['maker' => '4K Studio']);
        Movie::factory()->count(3)->create(['maker' => '★★★']);

        $names = fn(string $script, string $extra = ''): array => array_column($this->getJson('/api/v1/meta/catalog/makers?sort=name&script=' . $script . $extra, $this->headers())->assertOk()->json('data'), 'name');

        $this->assertSame(['4K Studio', 'Alpha'], $names('latin'));
        $this->assertSame(['ハイビジョン', '中出し'], $names('ja'));
        $this->assertSame(['巨乳', '美女'], $names('zh'));
        $this->assertSame(['巨乳'], $names('zh', '&min_count=3'));
        $this->assertSame(['★★★'], $names('other'));
        $this->assertCount(7, $names('all'));
        $response = $this->getJson('/api/v1/meta/catalog/makers?script=zh&min_count=3', $this->headers())->assertOk();
        $this->assertSame(1, $response->json('meta.pagination.total'));
        $this->assertSame(1, $response->json('meta.pagination.hidden_below_min'));
        $this->getJson('/api/v1/meta/catalog/makers?script=klingon', $this->headers())->assertStatus(400);
    }
}
