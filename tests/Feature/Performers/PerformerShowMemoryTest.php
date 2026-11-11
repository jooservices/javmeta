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

    public function test_profile_images_are_counted_and_the_rest_can_be_paginated(): void
    {
        $performer = Performer::factory()->create();
        $this->seedProfileImages($performer, 25);

        $detail = $this->getJson('/api/v1/performers/' . $performer->id, ['X-API-Key' => $this->apiKey])->assertOk();

        self::assertCount(24, $detail->json('data.photos.images'));
        self::assertSame(25, $detail->json('data.photos_total'));
        self::assertSame(25, $detail->json('data.profile_photos_total'));
        self::assertTrue($detail->json('data.truncated'));

        $page = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?kind=image&per_page=10&page=3', [
            'X-API-Key' => $this->apiKey,
        ])->assertOk();

        $page->assertJsonCount(5, 'data');
        self::assertSame(25, $page->json('meta.total'));
        self::assertSame('image', $page->json('data.0.kind'));
        self::assertSame('https://profile.test/' . $performer->id . '/24.jpg', $page->json('data.4.url'));
    }

    public function test_paginated_gallery_photos_keep_gallery_identity_and_position_across_pages(): void
    {
        $performer = Performer::factory()->create();
        foreach ([
            ['gallery_id' => 'gallery-a', 'position' => 2],
            ['gallery_id' => 'gallery-b', 'position' => 2],
            ['gallery_id' => 'gallery-a', 'position' => 1],
            ['gallery_id' => 'gallery-b', 'position' => 1],
        ] as $identity) {
            DB::table('performer_media')->insert([
                'performer_id' => $performer->id,
                'kind' => 'gallery',
                'url' => 'https://gallery.test/' . $identity['gallery_id'] . '/' . $identity['position'] . '.jpg',
                'meta' => json_encode([
                    ...$identity,
                    'gallery_title' => 'Title ' . $identity['gallery_id'],
                    'gallery_url' => 'https://gallery.test/' . $identity['gallery_id'],
                ], JSON_THROW_ON_ERROR),
                'source_slug' => 'memory-test',
                'crawled_at' => now()->toDateTimeString(),
            ]);
        }

        $headers = ['X-API-Key' => $this->apiKey];
        $first = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=3&page=1', $headers)
            ->assertOk()->json('data');
        $second = $this->getJson('/api/v1/performers/' . $performer->id . '/photos?per_page=3&page=2', $headers)
            ->assertOk()->json('data');

        self::assertSame(['gallery-a', 'gallery-a', 'gallery-b'], array_column($first, 'gallery_id'));
        self::assertSame([1, 2, 1], array_column($first, 'position'));
        self::assertSame('Title gallery-a', $first[0]['gallery_title']);
        self::assertSame('https://gallery.test/gallery-a', $first[0]['gallery_url']);
        self::assertSame(['gallery-b'], array_column($second, 'gallery_id'));
        self::assertSame([2], array_column($second, 'position'));
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

    private function seedProfileImages(Performer $performer, int $count): void
    {
        $timestamp = now()->toDateTimeString();
        for ($offset = 0; $offset < $count; $offset += 100) {
            $rows = [];
            for ($index = $offset; $index < min($count, $offset + 100); $index++) {
                $rows[] = [
                    'performer_id' => $performer->id,
                    'kind' => 'image',
                    'url' => 'https://profile.test/' . $performer->id . '/' . $index . '.jpg',
                    'meta' => '{}',
                    'source_slug' => 'memory-test',
                    'crawled_at' => $timestamp,
                ];
            }
            DB::table('performer_media')->insert($rows);
        }
    }
}
