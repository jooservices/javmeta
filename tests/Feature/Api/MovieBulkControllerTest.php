<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MovieBulkControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true), 60, ['bulk'])->plaintext;
    }

    public function test_bulk_requires_api_key(): void
    {
        $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['SSIS-001'],
        ])->assertUnauthorized()
            ->assertJsonPath('type', 'unauthorized')
            ->assertJsonMissingPath('data');
    }

    public function test_bulk_returns_per_code_status_ac61(): void
    {
        $this->createMovieWithCode('SSIS-001', 'SSIS001');
        $this->createMovieWithCode('ABC-123', 'ABC123');

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['SSIS-001', 'ABC-123', 'XYZ-999'],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.code', 'SSIS-001')
            ->assertJsonPath('data.0.status', 'found')
            ->assertJsonPath('data.0.movie.code', 'SSIS-001')
            ->assertJsonPath('data.1.status', 'found')
            ->assertJsonPath('data.2.code', 'XYZ-999')
            ->assertJsonPath('data.2.status', 'not_found')
            ->assertJsonPath('data.2.movie', null);
    }

    public function test_bulk_with_30_percent_not_found_still_returns_200_ac63(): void
    {
        foreach (['SSIS-001', 'SSIS-002', 'SSIS-003', 'SSIS-004', 'SSIS-005', 'SSIS-006', 'SSIS-007'] as $code) {
            $this->createMovieWithCode($code, str_replace('-', '', $code));
        }

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['SSIS-001', 'SSIS-002', 'SSIS-003', 'SSIS-004', 'SSIS-005', 'SSIS-006', 'SSIS-007', 'SSIS-101', 'SSIS-102', 'SSIS-103'],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()->assertJsonCount(10, 'data');

        $statuses = collect($response->json('data'))->pluck('status')->countBy();
        $this->assertSame(7, $statuses->get('found'));
        $this->assertSame(3, $statuses->get('not_found'));
    }

    public function test_bulk_duplicates_return_one_result_per_unique_normalized_code_ac64(): void
    {
        $this->createMovieWithCode('SSIS-001', 'SSIS001');

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['ssis001', 'SSIS-1', 'SSIS-001'],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SSIS-001')
            ->assertJsonPath('data.0.status', 'found');
    }

    public function test_bulk_duplicate_not_found_codes_are_not_repeated(): void
    {
        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['abc-999', 'ABC999', 'ABC-999'],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'ABC-999')
            ->assertJsonPath('data.0.status', 'not_found');
    }

    public function test_bulk_over_limit_returns_413_and_processes_none_ac62(): void
    {
        $codes = collect(range(1, 101))->map(fn(int $n): string => sprintf('SSIS-%03d', $n))->all();

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => $codes,
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(413)
            ->assertJsonPath('type', 'bulk_limit_exceeded')
            ->assertJsonMissingPath('data');

        $this->assertDatabaseCount('movies', 0);
    }

    public function test_bulk_over_limit_takes_precedence_over_invalid_items(): void
    {
        $codes = collect(range(1, 101))->map(fn(int $n): string => sprintf('SSIS-%03d', $n))->all();
        $codes[] = '';

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => $codes,
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertStatus(413)
            ->assertJsonPath('type', 'bulk_limit_exceeded');
    }

    public function test_bulk_empty_code_returns_invalid_filter(): void
    {
        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => [''],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_bulk_missing_codes_field_returns_invalid_filter(): void
    {
        $response = $this->postJson('/api/v1/movies:bulk', [], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_bulk_codes_not_an_array_returns_invalid_filter(): void
    {
        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => 'SSIS-001',
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertBadRequest()
            ->assertJsonPath('type', 'invalid_filter');
    }

    public function test_bulk_uses_batch_queries_without_n1(): void
    {
        foreach (range(1, 50) as $index) {
            $code = sprintf('SSIS-%03d', $index);
            $this->createMovieWithCode($code, str_replace('-', '', $code));
        }

        $codes = collect(range(1, 50))->map(fn(int $index): string => sprintf('SSIS-%03d', $index))->all();

        DB::enableQueryLog();

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => $codes,
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $queryCount = count(DB::getQueryLog());

        $response->assertOk()->assertJsonCount(50, 'data');

        // Auth + batch code lookup + eager-loaded extras + usage log stay far
        // below one query per code; an N+1 implementation would exceed 50.
        $this->assertLessThan(30, $queryCount);
    }

    public function test_bulk_malformed_code_is_reported_as_not_found_with_raw_code(): void
    {
        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => ['!!!'],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', '!!!')
            ->assertJsonPath('data.0.status', 'not_found');
    }

    public function test_bulk_lookup_with_spaced_lowercase_code_finds_movie(): void
    {
        $this->createMovieWithCode('SSIS-001', 'SSIS001');

        $response = $this->postJson('/api/v1/movies:bulk', [
            'codes' => [' ssis-001 '],
        ], [
            'X-API-Key' => $this->apiKey,
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'SSIS-001')
            ->assertJsonPath('data.0.status', 'found');
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
}
