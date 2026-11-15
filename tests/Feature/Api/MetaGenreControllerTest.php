<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Genre;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MetaGenreControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true), 60, ['catalog'])->plaintext;
    }

    public function test_meta_genres_require_api_key(): void
    {
        $this->getJson('/api/v1/meta/genres')
            ->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_meta_genres_returns_union_labels_deduplicated_and_sorted(): void
    {
        Genre::factory()->create(['label_normalized' => 'drama', 'label_raw' => 'Drama']);
        Genre::factory()->create(['label_normalized' => 'comedy', 'label_raw' => 'Comedy']);
        // Same raw label under a different normalized identity: union dedupe.
        Genre::factory()->create(['label_normalized' => 'drama-sub', 'label_raw' => 'Drama']);
        // Missing raw label falls back to the normalized label.
        Genre::factory()->create(['label_normalized' => 'romance', 'label_raw' => null]);

        $response = $this->getJson('/api/v1/meta/genres', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data', ['Comedy', 'Drama', 'romance']);
    }

    public function test_meta_genres_empty_database_returns_empty_list(): void
    {
        $response = $this->getJson('/api/v1/meta/genres', [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonPath('data', []);
    }
}
