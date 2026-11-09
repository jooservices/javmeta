<?php

declare(strict_types=1);

namespace Tests\Feature\Performers;

use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformerShowMemoryTest extends TestCase
{
    use RefreshDatabase;

    private string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = app(ApiKeyService::class)->create(fake()->words(2, true), 60, ['performers'])->plaintext;
    }

    public function test_performer_show_returns_at_most_preview_photos(): void
    {
        $performer = Performer::factory()->create();
        $this->seedGallery($performer, 500);

        $response = $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertSame(500, $response->json('data.photos_total'));
        self::assertTrue($response->json('data.truncated'));
        self::assertSame(24, array_sum(array_map(
            static fn(array $gallery): int => count($gallery['images']),
            $response->json('data.photos.galleries'),
        )));
    }

    public function test_performer_show_issues_no_unbounded_media_select(): void
    {
        $performer = Performer::factory()->create();
        $this->seedGallery($performer, 40);
        $mediaSelects = [];
        DB::listen(static function ($query) use (&$mediaSelects): void {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'performer_media') && str_starts_with(ltrim($sql), 'select') && ! str_contains($sql, 'count(')) {
                $mediaSelects[] = $sql;
            }
        });

        $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertNotEmpty($mediaSelects);
        foreach ($mediaSelects as $sql) {
            self::assertStringContainsString('limit', $sql);
        }
    }

    /** @group memory */
    public function test_performer_show_memory_growth_bounded(): void
    {
        $performer = Performer::factory()->create();
        $this->seedGallery($performer, 20_000);
        gc_collect_cycles();
        $before = memory_get_usage(true);
        memory_reset_peak_usage();

        $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertLessThan(32 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_performer_photos_endpoint_caps_per_page(): void
    {
        $performer = Performer::factory()->create();

        $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=500', ['X-API-Key' => $this->apiKey])
            ->assertStatus(422);
    }

    public function test_performer_photos_pages_are_stable(): void
    {
        $performer = Performer::factory()->create();
        $this->seedGallery($performer, 500);
        $headers = ['X-API-Key' => $this->apiKey];

        $first = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=200&page=1', $headers)
            ->assertOk()->assertJsonPath('meta.total', 500)->json('data');
        $second = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=200&page=2', $headers)->assertOk()->json('data');
        $third = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=200&page=3', $headers)->assertOk()->json('data');
        $urls = array_merge(array_column($first, 'url'), array_column($second, 'url'), array_column($third, 'url'));

        self::assertCount(500, $urls);
        self::assertCount(500, array_unique($urls));
        self::assertSame(100, count($third));
    }

    private function seedGallery(Performer $performer, int $count): void
    {
        $timestamp = now()->toDateTimeString();
        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = [];
            for ($index = $offset; $index < min($count, $offset + 500); $index++) {
                $rows[] = [
                    'performer_id' => $performer->id,
                    'kind' => 'gallery',
                    'url' => 'https://photos.test/' . $performer->id . '/' . $index . '.jpg',
                    'meta' => json_encode(['gallery_id' => 'gallery-' . $index, 'position' => 1], JSON_THROW_ON_ERROR),
                    'source_slug' => 'memory-test',
                    'crawled_at' => $timestamp,
                ];
            }
            DB::table('performer_media')->insert($rows);
        }
    }
}
