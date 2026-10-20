<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use App\Services\Mcp\McpToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fixes the public field catalog. REST and MCP return the same resources, so a key added or removed
 * without updating this list and docs/03-operations/api-contract.md fails here.
 */
final class FieldCatalogContractTest extends TestCase
{
    use RefreshDatabase;

    private const MOVIE_FIELDS = [
        'uuid', 'code', 'codes', 'title_jp', 'title_en', 'description', 'performers', 'directors', 'cover_url',
        'cover_thumb_url', 'release_date', 'runtime_minutes', 'maker', 'label', 'series', 'genres', 'censored',
        'community_score', 'completeness_tier', 'attrs', 'magnets', 'hls_stream_urls', 'gallery', 'photos',
        'crawled_at', 'delisted_at', 'needs_review',
    ];

    private const PERFORMER_FIELDS = [
        'uuid', 'id', 'name_romaji', 'name_kanji', 'name_kana', 'aliases', 'sources', 'birth_date', 'height_cm', 'bust', 'waist',
        'hip', 'cup', 'blood_type', 'bio_text', 'debut_date', 'retired_date', 'location', 'attrs', 'linked_title_count',
        'profile_url', 'image_url', 'photos',
    ];

    public function test_movie_detail_has_the_same_documented_fields_over_rest_and_mcp(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'SSIS-001', 'code_normalized' => 'SSIS001']);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'SSIS-001',
            'code_normalized' => 'SSIS001',
            'kind' => MovieCode::KIND_DVD,
            'source_slug' => 'javdb',
        ]);

        $rest = $this->getJson('/api/v1/movies/' . $movie->display_code, ['X-API-Key' => $this->apiKey()])
            ->assertOk()
            ->json('data');
        $mcp = app(McpToolService::class)->call('get_movie', ['code' => $movie->display_code]);

        self::assertEqualsCanonicalizing(self::MOVIE_FIELDS, array_keys($rest));
        self::assertEqualsCanonicalizing(self::MOVIE_FIELDS, array_keys($mcp));
        self::assertDocumented(self::MOVIE_FIELDS);
    }

    public function test_performer_detail_has_the_same_documented_fields_over_rest_and_mcp(): void
    {
        $performer = Performer::factory()->create(['retired_date' => '2020-05-06']);

        $rest = $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey()])
            ->assertOk()
            ->json('data');
        $mcp = app(McpToolService::class)->call('get_performer', ['id' => (string) $performer->id]);

        self::assertEqualsCanonicalizing(self::PERFORMER_FIELDS, array_keys($rest));
        self::assertEqualsCanonicalizing(self::PERFORMER_FIELDS, array_keys($mcp));
        self::assertSame('2020-05-06', $rest['retired_date']);
        self::assertSame('2020-05-06', $mcp['retired_date']);
        self::assertDocumented(self::PERFORMER_FIELDS);
    }

    public function test_retired_date_is_null_when_no_source_provides_it(): void
    {
        $performer = Performer::factory()->create();

        $payload = $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey()])
            ->assertOk()
            ->json('data');

        self::assertArrayHasKey('retired_date', $payload);
        self::assertNull($payload['retired_date']);
    }

    /** @param list<string> $fields */
    private static function assertDocumented(array $fields): void
    {
        $document = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/03-operations/api-contract.md');

        foreach ($fields as $field) {
            self::assertStringContainsString('`' . $field . '`', $document, "Field {$field} is missing from api-contract.md");
        }
    }

    private function apiKey(): string
    {
        return app(ApiKeyService::class)->create('field-catalog')->plaintext;
    }
}
