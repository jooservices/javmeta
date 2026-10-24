<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use App\Services\Auth\ApiKeyService;
use App\Services\Mcp\McpToolService;
use App\Services\Media\MediaQueue;
use App\Services\Media\SceneSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SceneSearchTest extends TestCase
{
    use RefreshDatabase;

    private function scene(string $description = 'A red room at night.'): int
    {
        $file = DB::table('library_files')->insertGetId(['path' => '/generated/' . uniqid() . '.mp4', 'folder' => '/generated', 'code' => 'GEN-001', 'size' => 1, 'mtime' => 1, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        return DB::table('media_scenes')->insertGetId(['library_file_id' => $file, 'start_sec' => 1.5, 'end_sec' => 3.0, 'thumb_path' => '/generated/scene.jpg', 'description' => $description]);
    }

    private function headers(string $scope = 'movies'): array
    {
        return ['X-API-Key' => app(ApiKeyService::class)->create('scene tests', 120, [$scope])->plaintext];
    }

    public function test_keyword_fallback_contract_limits_and_scope(): void
    {
        config(['elasticsearch.enabled' => false]);
        $this->scene();
        $h = $this->headers();
        $data = $this->getJson('/api/v1/scenes/search?q=red&per_page=1', $h)->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('GEN-001', $data[0]['movie_code']);
        $this->assertSame(1.5, $data[0]['start_sec']);
        $this->assertNull($data[0]['thumb_url']);
        $this->getJson('/api/v1/scenes/search?q=red', $this->headers('media'))->assertForbidden();
        $this->getJson('/api/v1/scenes/search?q=re', $h)->assertUnprocessable();
        $this->getJson('/api/v1/scenes/search?q=red&per_page=51', $h)->assertUnprocessable();
        $this->getJson('/api/v1/scenes/search?q=%25%25%25', $h)->assertOk()->assertJsonPath('data', []);
        $wire = json_decode(json_encode(app(McpToolService::class)->call('search_scenes', ['q' => 'red', 'per_page' => 1])['items'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($data, $wire);
        $this->assertSame('invalid_filter', app(McpToolService::class)->call('search_scenes', ['q' => 're'])['error']);
    }

    public function test_vector_index_is_created_lazily_and_search_preserves_scores(): void
    {
        config(['elasticsearch.enabled' => true, 'elasticsearch.embedder_enabled' => true, 'javmeta_ai.embedding_provider' => 'embedder', 'javmeta_media.embedding_dimensions' => 8]);
        $id = $this->scene();
        $file = (int) DB::table('media_scenes')->where('id', $id)->value('library_file_id');
        Http::fake(static function ($request) use ($id) {
            if (str_contains($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 8, .1)]]]);
            }
            if ($request->method() === 'HEAD') {
                return Http::response([], 404);
            }
            if (str_ends_with($request->url(), '/_search')) {
                return Http::response(['hits' => ['hits' => [['_id' => (string) $id, '_score' => .95]]]]);
            }
            return Http::response(['acknowledged' => true]);
        });
        app(SceneSearchService::class)->indexFile($file);
        $this->assertNotNull(DB::table('media_scenes')->where('id', $id)->value('embedding_indexed_at'));
        Http::assertSent(static fn($r): bool => $r->method() === 'PUT' && isset($r['mappings']['properties']['embedding']));
        $this->getJson('/api/v1/scenes/search?q=night', $this->headers())->assertOk()->assertJsonPath('data.0.score', .95);
    }

    public function test_unavailable_vectors_fall_back_and_missing_files_are_excluded(): void
    {
        config(['elasticsearch.enabled' => true, 'elasticsearch.embedder_enabled' => true]);
        Http::fake(['*' => Http::response([], 503)]);
        $id = $this->scene();
        $this->getJson('/api/v1/scenes/search?q=red', $this->headers())->assertOk()->assertJsonCount(1, 'data');
        $file = DB::table('media_scenes')->where('id', $id)->value('library_file_id');
        DB::table('library_files')->where('id', $file)->update(['missing_at' => now()]);
        $this->getJson('/api/v1/scenes/search?q=red', $this->headers())->assertOk()->assertJsonPath('data', []);
    }

    public function test_description_ingest_cannot_update_another_files_scene(): void
    {
        config(['elasticsearch.enabled' => false]);
        $id = $this->scene();
        $other = $this->scene();
        $file = (int) DB::table('media_scenes')->where('id', $id)->value('library_file_id');
        $q = app(MediaQueue::class);
        $job = $q->enqueue('describe', $file);
        $claim = $q->claim('vision');
        $body = ['claim_token' => $claim['claim_token'], 'result' => ['descriptions' => [['id' => $other, 'description' => 'A blue frame.', 'tags' => ['blue'], 'confidence' => .8]]]];
        $h = $this->headers('media');
        $this->postJson("/api/v1/media/jobs/{$job}/complete", $body, $h)->assertUnprocessable();
        $body['result']['descriptions'][0]['id'] = $id;
        $this->postJson("/api/v1/media/jobs/{$job}/complete", $body, $h)->assertOk();
        $this->assertDatabaseHas('media_scenes', ['id' => $id, 'description' => 'A blue frame.', 'confidence' => .8]);
        $this->assertDatabaseHas('media_scenes', ['id' => $other, 'description' => 'A red room at night.']);
    }
}
