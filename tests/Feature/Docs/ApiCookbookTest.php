<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\DependencyMonitorTestHelper;
use Tests\TestCase;

final class ApiCookbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_cookbook_requests_work_against_the_application(): void
    {
        DependencyMonitorTestHelper::bind();
        config(['elasticsearch.enabled' => false, 'javmeta_ai.nl_enabled' => false, 'javmeta_auth.owner_admin_token' => 'cookbook-owner-fixture']);
        $key = app(ApiKeyService::class)->create('cookbook-fixture', 100)->plaintext;
        $movie = Movie::factory()->create(['display_code' => 'SSIS-001', 'code_normalized' => 'SSIS001']);
        MovieCode::factory()->create(['movie_id' => $movie->id, 'code' => 'SSIS-001', 'code_normalized' => 'SSIS001']);
        Performer::factory()->create(['id' => 1]);
        $examples = json_decode((string) file_get_contents(base_path('tools/docs/api-examples.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($examples as $example) {
            $owner = $example['owner'] ?? false;
            $headers = $owner ? ['X-Owner-Token' => 'cookbook-owner-fixture'] : ['X-API-Key' => $key];
            $response = $this->json($example['method'], $example['path'], $example['body'] ?? [], $headers);
            $response->assertSuccessful();
            if (str_contains($example['path'], '/mcp')) {
                $this->assertNull($response->json('error'), $example['path']);
                $this->assertNotSame(true, $response->json('result.isError'));
            }
        }
    }
}
