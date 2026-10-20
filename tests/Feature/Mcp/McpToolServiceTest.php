<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\CrawlQueue;
use App\Models\Genre;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Models\Source;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Mcp\McpToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class McpToolServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_definitions_expose_collection_and_detail_tools(): void
    {
        $definitions = app(McpToolService::class)->definitions();
        $names = collect($definitions)->pluck('name')->all();

        $this->assertSame([
            'lookup_movies',
            'get_movie',
            'search',
            'lookup_performers',
            'get_counts',
            'crawl_status',
            'system_status',
            'get_performer',
        ], $names);

        $search = collect($definitions)->firstWhere('name', 'search');
        $this->assertSame(['movie', 'performer', 'all'], $search['inputSchema']['properties']['entity']['enum']);

        $movies = collect(app(McpToolService::class)->definitions())->firstWhere('name', 'lookup_movies');
        $this->assertArrayHasKey('count_only', $movies['inputSchema']['properties']);

        $guide = file_get_contents(base_path('docs/03-operations/mcp.md'));
        $this->assertIsString($guide);
        foreach ($definitions as $definition) {
            preg_match('/^\| `' . preg_quote($definition['name'], '/') . '` \|.*\|$/m', $guide, $matches);
            $this->assertCount(1, $matches, "Missing MCP guide row for {$definition['name']}.");
            $toolRow = $matches[0] ?? '';
            foreach (array_keys($definition['inputSchema']['properties']) as $input) {
                $this->assertStringContainsString('`' . $input . '`', $toolRow, "Missing MCP guide input {$input} for {$definition['name']}.");
            }
        }
    }

    public function test_lookup_movies_returns_filter_sort_and_cursor_metadata(): void
    {
        $genre = Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        $movies = Movie::factory()->count(3)->create(['title_en' => 'Drama collection']);
        foreach ($movies as $movie) {
            MovieGenre::factory()->create([
                'movie_id' => $movie->id,
                'genre_id' => $genre->id,
                'source_slug' => fake()->slug(2),
            ]);
        }

        $service = app(McpToolService::class);
        $pageOne = $service->call('lookup_movies', [
            'q' => 'Drama',
            'genre' => 'drama',
            'sort' => 'release_date',
            'per_page' => 2,
        ]);

        $this->assertCount(2, $pageOne['items']);
        $this->assertTrue($pageOne['pagination']['has_more']);
        $this->assertNotEmpty($pageOne['pagination']['next_cursor']);

        $pageTwo = $service->call('lookup_movies', [
            'q' => 'Drama',
            'genre' => 'drama',
            'sort' => 'release_date',
            'per_page' => 2,
            'cursor' => $pageOne['pagination']['next_cursor'],
        ]);

        $this->assertCount(1, $pageTwo['items']);
        $this->assertSame(3, $pageTwo['pagination']['total']);
    }

    public function test_get_movie_returns_full_media_urls_and_thumbnail(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'SSIS-001', 'code_normalized' => 'SSIS001']);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
        ]);
        $galleryMedia = MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_GALLERY,
            'url' => fake()->imageUrl(),
            'source_slug' => fake()->slug(2),
            'meta' => [
                'gallery_id' => fake()->uuid(),
                'gallery_title' => fake()->sentence(),
                'gallery_url' => fake()->url(),
                'thumbnail_url' => fake()->imageUrl(),
                'position' => 1,
            ],
        ]);

        $payload = app(McpToolService::class)->call('get_movie', ['code' => 'SSIS-001']);

        $this->assertSame('SSIS-001', $payload['code']);
        $this->assertArrayHasKey('gallery', $payload);
        $this->assertArrayHasKey('thumbnail_url', $payload['gallery'][0]);
        $this->assertSame($galleryMedia->url, $payload['photos']['galleries'][0]['images'][0]['url']);
        $this->assertSame($galleryMedia->source_slug, $payload['photos']['galleries'][0]['source']);
    }

    public function test_lookup_performers_searches_bio_and_returns_cursor_metadata(): void
    {
        $bioTerm = fake()->unique()->words(2, true);
        Performer::factory()->count(3)->create(['bio_text' => "Profile {$bioTerm}"]);

        $payload = app(McpToolService::class)->call('lookup_performers', [
            'q' => $bioTerm,
            'sort' => 'name',
            'per_page' => 2,
        ]);

        $this->assertCount(2, $payload['items']);
        $this->assertTrue($payload['pagination']['has_more']);
        $this->assertNotEmpty($payload['pagination']['next_cursor']);
        $this->assertSame(3, $payload['pagination']['total']);
    }

    public function test_lookup_performers_filters_age_and_body_information(): void
    {
        Carbon::setTestNow('2026-09-30');
        $matching = Performer::factory()->create([
            'birth_date' => '1996-01-01',
            'blood_type' => 'A',
            'hip' => 92,
        ]);
        Performer::factory()->create([
            'birth_date' => '1980-01-01',
            'blood_type' => 'A',
            'hip' => 92,
        ]);

        $payload = app(McpToolService::class)->call('lookup_performers', [
            'age_min' => 25,
            'age_max' => 35,
            'blood_type' => 'A',
            'hip_min' => 90,
            'hip_max' => 95,
        ]);

        Carbon::setTestNow();

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame($matching->id, $payload['items'][0]['id']);
    }

    public function test_get_performer_accepts_uuid_and_returns_full_profile(): void
    {
        $performer = Performer::factory()->create([
            'bio_text' => fake()->paragraph(),
            'blood_type' => 'B',
        ]);

        $payload = app(McpToolService::class)->call('get_performer', ['id' => $performer->uuid]);

        $this->assertSame($performer->uuid, $payload['uuid']);
        $this->assertSame($performer->bio_text, $payload['bio_text']);
        $this->assertSame('B', $payload['blood_type']);
    }

    public function test_get_performer_returns_common_photos_contract(): void
    {
        $performer = Performer::factory()->create();
        $profile = PerformerMedia::factory()->create([
            'performer_id' => $performer->id,
            'kind' => PerformerMedia::KIND_IMAGE,
            'url' => fake()->imageUrl(),
            'source_slug' => fake()->slug(2),
        ]);
        $gallery = PerformerMedia::factory()->create([
            'performer_id' => $performer->id,
            'kind' => PerformerMedia::KIND_GALLERY,
            'url' => fake()->imageUrl(),
            'source_slug' => fake()->slug(2),
            'meta' => [
                'gallery_id' => fake()->uuid(),
                'gallery_title' => fake()->sentence(),
                'gallery_url' => fake()->url(),
                'thumbnail_url' => fake()->imageUrl(),
                'position' => 1,
            ],
        ]);

        $payload = app(McpToolService::class)->call('get_performer', ['id' => $performer->uuid]);

        self::assertSame($profile->url, $payload['photos']['images'][0]['url']);
        self::assertSame($profile->source_slug, $payload['photos']['images'][0]['source']);
        self::assertSame($gallery->url, $payload['photos']['galleries'][0]['images'][0]['url']);
        self::assertSame($gallery->source_slug, $payload['photos']['galleries'][0]['source']);
    }

    public function test_search_returns_movies_by_natural_language(): void
    {
        $movie = Movie::factory()->create([
            'title_en' => 'A Cheeky Little Devil Schoolgirl At A Tavern Part-time Job',
        ]);
        Movie::factory()->create(['title_en' => 'Unrelated documentary about fishing']);

        $payload = app(McpToolService::class)->call('search', [
            'q' => 'cheeky',
        ]);

        $this->assertSame('cheeky', $payload['query']);
        $this->assertGreaterThanOrEqual(1, $payload['pagination']['total']);
        $this->assertSame($movie->uuid, $payload['items'][0]['uuid']);
    }

    public function test_search_returns_performers_by_natural_language(): void
    {
        $term = fake()->unique()->words(2, true);
        $performer = Performer::factory()->create(['bio_text' => "Profile {$term}"]);

        $payload = app(McpToolService::class)->call('search', [
            'q' => $term,
            'entity' => 'performer',
        ]);

        $this->assertSame($term, $payload['query']);
        $this->assertSame($performer->uuid, $payload['items'][0]['uuid']);
        $this->assertSame(1, $payload['pagination']['total']);
    }

    public function test_search_all_returns_movies_and_performers_separately(): void
    {
        $term = fake()->unique()->words(2, true);
        $movie = Movie::factory()->create(['title_en' => "Movie {$term}"]);
        $performer = Performer::factory()->create(['bio_text' => "Profile {$term}"]);

        $payload = app(McpToolService::class)->call('search', [
            'q' => $term,
            'entity' => 'all',
        ]);

        $this->assertSame($movie->uuid, $payload['movies']['items'][0]['uuid']);
        $this->assertSame($performer->uuid, $payload['performers']['items'][0]['uuid']);
    }

    public function test_search_rejects_unknown_entity(): void
    {
        $payload = app(McpToolService::class)->call('search', [
            'q' => fake()->sentence(),
            'entity' => 'unknown',
        ]);

        $this->assertSame('invalid_filter', $payload['error']);
    }

    public function test_lookup_movies_count_only_returns_count_without_items(): void
    {
        Movie::factory()->count(5)->create();

        $payload = app(McpToolService::class)->call('lookup_movies', ['count_only' => true]);

        $this->assertSame(5, $payload['count']);
        $this->assertArrayNotHasKey('items', $payload);
        $this->assertArrayNotHasKey('pagination', $payload);
    }

    public function test_lookup_performers_count_only_returns_count_without_items(): void
    {
        Performer::factory()->count(3)->create();

        $payload = app(McpToolService::class)->call('lookup_performers', ['count_only' => true]);

        $this->assertSame(3, $payload['count']);
        $this->assertArrayNotHasKey('items', $payload);
    }

    public function test_get_counts_returns_movies_and_performers_totals(): void
    {
        Movie::factory()->count(4)->create();
        Performer::factory()->count(2)->create();

        $payload = app(McpToolService::class)->call('get_counts', []);

        $this->assertSame(4, $payload['movies']);
        $this->assertSame(2, $payload['performers']);
    }

    public function test_crawl_status_returns_queue_sources_and_workers(): void
    {
        Movie::factory()->count(2)->create();
        $source = Source::factory()->create();
        CrawlQueue::factory()->create([
            'source_slug' => $source->slug,
            'kind' => CrawlQueue::KIND_DETAIL,
            'status' => CrawlQueue::STATUS_PENDING,
        ]);
        app(WorkerHeartbeat::class)->beat('worker-a');

        $payload = app(McpToolService::class)->call('crawl_status', []);

        $this->assertSame(2, $payload['counts']['movies']);
        $this->assertSame(1, $payload['queue']['by_status']['pending']);
        $this->assertSame('closed', $payload['sources'][0]['circuit_state'], 'deprecated key kept stable');
        $this->assertSame('worker-a', $payload['workers'][0]['instance']);
        $this->assertFalse($payload['workers'][0]['stale']);
    }

    public function test_system_status_reports_service_probes(): void
    {
        Http::fake([
            'http://es.example/*' => Http::response(['version' => ['number' => '8.15']], 200),
            'http://embedder.example/*' => Http::response(['status' => 'ok'], 200),
        ]);
        config(['elasticsearch.host' => 'http://es.example']);
        config(['elasticsearch.embedder_url' => 'http://embedder.example']);
        config(['openobserve.enabled' => false]);

        $payload = app(McpToolService::class)->call('system_status', []);

        $this->assertSame('ok', $payload['services']['database']['status']);
        $this->assertSame('ok', $payload['services']['elasticsearch']['status']);
        $this->assertSame('ok', $payload['services']['embedder']['status']);
        $this->assertSame('disabled', $payload['services']['observability']['status']);
        $this->assertContains($payload['status'], ['ok', 'degraded']);
    }
}
