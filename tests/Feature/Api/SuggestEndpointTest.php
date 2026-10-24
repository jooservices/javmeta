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

        $this->assertSame([['name' => 'Abacus', 'movie_count' => 1]], $this->getJson('/api/v1/suggest?q=abac', $this->headers())->json('data.makers'));
    }
}
