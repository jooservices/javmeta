<?php

declare(strict_types=1);

namespace Tests\Feature\Movies;

use App\Http\Resources\MovieSummaryResource;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Repositories\MovieRepository;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MovieMediaEagerLoadTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true), 60, ['movies'])->plaintext;
    }

    public function test_movie_search_loads_cover_media_only(): void
    {
        $movies = Movie::factory()->count(20)->create();
        $this->seedGalleries($movies->all(), 300);

        $result = app(MovieRepository::class)->search([], 'release_date', null, 20);

        self::assertCount(20, $result['items']);
        foreach ($result['items'] as $movie) {
            self::assertFalse($movie->relationLoaded('media'));
            self::assertTrue($movie->relationLoaded('coverMedia'));
        }
    }

    /** @group memory */
    public function test_movie_search_page_memory_growth_is_bounded(): void
    {
        $movies = Movie::factory()->count(100)->create();
        $this->seedGalleries($movies->all(), 300);
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        app(MovieRepository::class)->search([], 'release_date', null, 100);

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_movie_detail_caps_gallery_with_truncated_flag(): void
    {
        $movie = $this->movieWithCode();
        $this->seedGalleries([$movie], 1_000);

        $response = $this->getJson('/api/v1/movies/' . $movie->display_code, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertCount(100, $response->json('data.gallery'));
        self::assertSame(1_000, $response->json('data.gallery_total'));
        self::assertTrue($response->json('data.truncated'));
    }

    public function test_movie_summary_cover_url_is_unchanged(): void
    {
        $movie = Movie::factory()->create(['cover_url' => null]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_SAMPLE,
            'url' => 'https://cover.test/sample.jpg',
        ]);
        $result = app(MovieRepository::class)->search([], 'release_date', null, 1);
        $summary = (new MovieSummaryResource($result['items']->first()))->resolve(Request::create('/'));

        self::assertSame('https://cover.test/sample.jpg', $summary['cover_url']);
    }

    private function movieWithCode(): Movie
    {
        $movie = Movie::factory()->create([
            'display_code' => 'MEM-DETAIL-001',
            'code_normalized' => 'MEMDETAIL001',
        ]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => $movie->display_code,
            'code_normalized' => $movie->code_normalized,
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'memory-test',
        ]);

        return $movie;
    }

    /** @param list<Movie> $movies */
    private function seedGalleries(array $movies, int $perMovie): void
    {
        $timestamp = now()->toDateTimeString();
        foreach ($movies as $movie) {
            for ($offset = 0; $offset < $perMovie; $offset += 500) {
                $rows = [];
                for ($index = $offset; $index < min($perMovie, $offset + 500); $index++) {
                    $rows[] = [
                        'movie_id' => $movie->id,
                        'kind' => MovieMedia::KIND_GALLERY,
                        'url' => 'https://gallery.test/' . $movie->id . '/' . $index . '.jpg',
                        'meta' => json_encode(['thumbnail_url' => 'https://thumb.test/' . $movie->id . '/' . $index . '.jpg'], JSON_THROW_ON_ERROR),
                        'source_slug' => 'memory-test',
                        'crawled_at' => $timestamp,
                    ];
                }
                DB::table('movie_media')->insert($rows);
            }
        }
    }
}
