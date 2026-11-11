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
        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true), 60, ['movies', 'bulk'])->plaintext;
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

    public function test_movie_detail_bounds_each_non_gallery_media_kind_and_exposes_all_pages(): void
    {
        $movie = $this->movieWithCode();
        $this->seedNonGalleryMedia([$movie], 250);

        $detail = $this->getJson('/api/v1/movies/' . $movie->display_code, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertCount(100, $detail->json('data.magnets'));
        self::assertCount(100, $detail->json('data.hls_stream_urls'));
        self::assertSame(250, $detail->json('data.media_totals.magnets.total'));
        self::assertTrue($detail->json('data.media_totals.magnets.truncated'));
        self::assertSame(250, $detail->json('data.media_totals.hls_stream_urls.total'));

        $page = $this->getJson('/api/v1/movies/' . $movie->display_code . '/media?kind=magnet&per_page=100&page=3', [
            'X-API-Key' => $this->apiKey,
        ])->assertOk();

        $page->assertJsonCount(50, 'data');
        self::assertSame(250, $page->json('meta.total'));
        self::assertSame('magnet', $page->json('data.0.kind'));
        self::assertSame(3, $page->json('meta.pagination.current_page'));
    }

    /** @group memory */
    public function test_movie_detail_memory_growth_stays_bounded_with_many_non_gallery_rows(): void
    {
        $movie = $this->movieWithCode();
        $this->seedNonGalleryMedia([$movie], 20_000, [MovieMedia::KIND_MAGNET]);
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $this->getJson('/api/v1/movies/' . $movie->display_code, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    /** @group memory */
    public function test_movie_bulk_lookup_memory_growth_stays_bounded_with_many_non_gallery_rows(): void
    {
        $movies = [
            $this->movieWithCode(),
            $this->movieWithCode('-002'),
        ];
        $this->seedNonGalleryMedia($movies, 10_000, [MovieMedia::KIND_MAGNET]);
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => array_map(static fn(Movie $movie): string => (string) $movie->display_code, $movies),
        ], ['X-API-Key' => $this->apiKey])->assertOk();

        $response->assertJsonCount(2, 'data');
        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
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

    private function movieWithCode(string $suffix = ''): Movie
    {
        $movie = Movie::factory()->create([
            'display_code' => 'MEM-DETAIL-001' . $suffix,
            'code_normalized' => 'MEMDETAIL001' . str_replace('-', '', $suffix),
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

    /** @param list<Movie> $movies */
    /** @param list<string> $kinds */
    private function seedNonGalleryMedia(array $movies, int $perKind, array $kinds = [MovieMedia::KIND_MAGNET, MovieMedia::KIND_HLS, MovieMedia::KIND_SAMPLE]): void
    {
        $timestamp = now()->toDateTimeString();
        foreach ($movies as $movie) {
            foreach ($kinds as $kind) {
                for ($offset = 0; $offset < $perKind; $offset += 500) {
                    $rows = [];
                    for ($index = $offset; $index < min($perKind, $offset + 500); $index++) {
                        $rows[] = [
                            'movie_id' => $movie->id,
                            'kind' => $kind,
                            'url' => 'https://media.test/' . $movie->id . '/' . $kind . '/' . $index,
                            'meta' => '{}',
                            'source_slug' => 'memory-test',
                            'crawled_at' => $timestamp,
                        ];
                    }
                    DB::table('movie_media')->insert($rows);
                }
            }
        }
    }
}
