<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieGenre;
use App\Models\MovieMedia;
use App\Models\MovieObservation;
use App\Models\MoviePerformer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MovieLookupControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SEED_COUNT = 5000;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true))->plaintext;
    }

    public function test_lookup_requires_api_key_ac34(): void
    {
        $this->getJson('/api/v1/movies/SSIS-001')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_lookup_with_wrong_key_returns_unauthorized_ac34(): void
    {
        $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => 'jvm_' . fake()->sha256(),
        ])->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_lookup_with_revoked_key_returns_unauthorized_ac34(): void
    {
        $service = app(ApiKeyService::class);
        $created = $service->create(fake()->words(2, true));
        $service->revoke((int) $created->model->getKey());

        $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => $created->plaintext,
        ])->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_lookup_finds_movie_by_code_ac31(): void
    {
        $movie = $this->createMovieWithCode('SSIS-001', 'SSIS001');

        $response = $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.code', 'SSIS-001')
            ->assertJsonPath('data.completeness_tier', $movie->completeness_tier)
            ->assertJsonPath('data.codes.0.code', 'SSIS-001')
            ->assertJsonPath('data.codes.0.kind', MovieCode::KIND_DVD)
            ->assertJsonPath('data.needs_review', false)
            ->assertJsonPath('data.delisted_at', null);
        $this->assertArrayNotHasKey('source', $response->json('data.codes.0'));
    }

    public function test_lookup_normalizes_code_variants_ac32(): void
    {
        $this->createMovieWithCode('SSIS-001', 'SSIS001');

        foreach (['ssis001', 'SSIS-1', ' ssis-001 '] as $variant) {
            $response = $this->getJson('/api/v1/movies/' . rawurlencode($variant), [
                'X-API-Key' => $this->apiKey,
            ]);

            $response->assertOk()
                ->assertJsonPath('data.code', 'SSIS-001');
        }
    }

    public function test_lookup_unknown_code_returns_movie_not_found_ac33(): void
    {
        $response = $this->getJson('/api/v1/movies/XYZ-999', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('type', 'movie_not_found')
            ->assertJsonMissingPath('data');
    }

    public function test_lookup_malformed_code_returns_movie_not_found_not_500(): void
    {
        $response = $this->getJson('/api/v1/movies/%21%21%21', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertNotFound()
            ->assertJsonPath('type', 'movie_not_found')
            ->assertJsonMissingPath('data');
    }

    public function test_lookup_returns_full_adr_resource_shape(): void
    {
        $this->createMovieWithRelations();

        $response = $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()->assertJsonStructure(['data' => [
            'uuid',
            'code',
            'codes' => [['code', 'kind']],
            'title_jp',
            'title_en',
            'description',
            'performers' => [['uuid', 'id', 'name_romaji', 'name_kanji', 'name_kana', 'aliases', 'sources', 'image_url', 'profile_url']],
            'directors',
            'cover_url',
            'cover_thumb_url',
            'release_date',
            'runtime_minutes',
            'maker',
            'label',
            'series',
            'genres',
            'censored',
            'community_score',
            'completeness_tier',
            'attrs',
            'magnets' => [['url', 'crawled_at']],
            'hls_stream_urls' => [['url', 'crawled_at']],
            'gallery' => [['url', 'crawled_at']],
            'crawled_at',
            'delisted_at',
            'needs_review',
        ]]);
        $this->assertArrayNotHasKey('provenance', $response->json('data'));

        $performer = $response->json('data.performers.0');
        $this->assertIsArray($performer);
        $this->assertArrayNotHasKey('bust', $performer);
        $this->assertArrayNotHasKey('bio_text', $performer);
        $this->assertArrayNotHasKey('linked_title_count', $performer);
    }

    public function test_lookup_genres_union_deduplicates_labels_ac16(): void
    {
        $movie = $this->createMovieWithCode('SSIS-001', 'SSIS001');
        $genre = Genre::factory()->create(['label_raw' => 'Drama']);
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id, 'source_slug' => 'javdb']);
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id, 'source_slug' => 'onejav']);

        $response = $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()->assertJsonCount(1, 'data.genres');
    }

    public function test_lookup_omits_source_provenance_for_end_users(): void
    {
        $movie = $this->createMovieWithCode('SSIS-001', 'SSIS001');
        $crawledAt = now()->subDay();

        MovieObservation::create([
            'movie_id' => $movie->id,
            'field' => 'title_jp',
            'value' => 'Authoritative title',
            'value_hash' => hash('sha256', 'Authoritative title'),
            'source_slug' => 'javdb',
            'crawled_at' => $crawledAt,
            'is_primary' => false,
        ]);

        $response = $this->getJson('/api/v1/movies/SSIS-001', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonMissingPath('data.provenance');
    }

    public function test_lookup_p95_smoke_under_bounded_threshold_at_5k_scale(): void
    {
        $target = $this->seedMoviesForSmokeTest();

        $start = hrtime(true);
        $response = $this->getJson('/api/v1/movies/' . $target->display_code, [
            'X-API-Key' => $this->apiKey,
        ]);
        $elapsedMs = (int) round((hrtime(true) - $start) / 1_000_000);

        $response->assertOk()->assertJsonPath('data.code', $target->display_code);

        // NFR lookup p95 <= 500 ms at POC scale; a generous bound absorbs CI
        // variance while still catching pathological query patterns.
        $this->assertLessThan(5000, $elapsedMs);
    }

    private function createMovieWithCode(string $displayCode, string $normalizedCode): Movie
    {
        $movie = Movie::factory()->create([
            'display_code' => $displayCode,
            'code_normalized' => $normalizedCode,
        ]);

        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => $displayCode,
            'code_normalized' => $normalizedCode,
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'javdb',
        ]);

        return $movie;
    }

    private function createMovieWithRelations(): void
    {
        $movie = $this->createMovieWithCode('SSIS-001', 'SSIS001');

        $movie->forceFill([
            'title_jp' => fake()->sentence(3),
            'title_en' => fake()->sentence(3),
            'release_date' => '2021-01-01',
            'runtime_minutes' => 120,
            'censored' => Movie::CENSORED_CENSORED,
            'maker' => fake()->company(),
            'label' => fake()->companySuffix(),
            'series' => fake()->words(2, true),
            'community_score' => 8.4,
        ])->save();

        $performer = Performer::factory()->create();
        PerformerAlias::create([
            'performer_id' => $performer->id,
            'alias' => fake()->unique()->words(2, true),
            'kind' => 'stage',
        ]);
        MoviePerformer::factory()->create([
            'movie_id' => $movie->id,
            'performer_id' => $performer->id,
            'source_slug' => 'javdb',
        ]);

        $genre = Genre::factory()->create(['label_raw' => 'Drama']);
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id, 'source_slug' => 'javdb']);

        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_MAGNET,
            'url' => 'magnet:?xt=urn:btih:' . fake()->sha1(),
            'source_slug' => 'onejav',
        ]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_HLS,
            'url' => fake()->url(),
            'source_slug' => 'missav',
        ]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_GALLERY,
            'url' => fake()->url(),
            'meta' => ['thumbnail_url' => fake()->imageUrl()],
            'source_slug' => 'javdb',
        ]);
        MovieMedia::factory()->create([
            'movie_id' => $movie->id,
            'kind' => MovieMedia::KIND_SAMPLE,
            'url' => fake()->url(),
            'source_slug' => 'javdb',
        ]);
    }

    private function seedMoviesForSmokeTest(): Movie
    {
        $movies = Movie::factory()->count(self::SEED_COUNT)->create();

        MovieCode::insert($movies->map(static fn(Movie $movie): array => [
            'movie_id' => $movie->id,
            'code' => $movie->display_code,
            'code_normalized' => $movie->code_normalized,
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'javdb',
            'source_url' => null,
            'crawled_at' => now(),
        ])->all());

        return $movies->first();
    }
}
