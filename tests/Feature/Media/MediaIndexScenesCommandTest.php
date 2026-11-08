<?php

declare(strict_types=1);

namespace Tests\Feature\Media;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MediaIndexScenesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_retries_only_files_with_described_unindexed_scenes(): void
    {
        $eligible = $this->file('/generated/eligible.mp4');
        $indexed = $this->file('/generated/indexed.mp4');
        $emptyDescription = $this->file('/generated/empty.mp4');
        $indexedAt = now()->subMinutes(5);
        DB::table('media_scenes')->insert([
            ['library_file_id' => $eligible, 'start_sec' => 1, 'end_sec' => 2, 'thumb_path' => '/generated/eligible.jpg', 'description' => 'A blue room.', 'embedding_indexed_at' => null],
            ['library_file_id' => $indexed, 'start_sec' => 1, 'end_sec' => 2, 'thumb_path' => '/generated/indexed.jpg', 'description' => 'A green room.', 'embedding_indexed_at' => $indexedAt],
            ['library_file_id' => $emptyDescription, 'start_sec' => 1, 'end_sec' => 2, 'thumb_path' => '/generated/empty.jpg', 'description' => null, 'embedding_indexed_at' => null],
        ]);

        config([
            'elasticsearch.embedder_url' => 'http://embedder.test',
            'elasticsearch.host' => 'http://elasticsearch.test',
            'javmeta_media.embedding_dimensions' => 384,
        ]);
        Http::preventStrayRequests();
        Http::fake(static function (ClientRequest $request) {
            if (str_ends_with($request->url(), '/v1/embeddings')) {
                return Http::response(['data' => [['embedding' => array_fill(0, 384, 0.25)]]], 200);
            }
            if ($request->method() === 'HEAD') {
                return Http::response([], 404);
            }

            return Http::response(['result' => 'created'], 200);
        });

        $this->artisan('media:index-scenes')
            ->expectsOutputToContain('Scene index retry finished; unavailable vectors remain pending.')
            ->assertSuccessful();

        self::assertNotNull(DB::table('media_scenes')->where('library_file_id', $eligible)->value('embedding_indexed_at'));
        self::assertSame($indexedAt->toDateTimeString(), DB::table('media_scenes')->where('library_file_id', $indexed)->value('embedding_indexed_at'));
        self::assertNull(DB::table('media_scenes')->where('library_file_id', $emptyDescription)->value('embedding_indexed_at'));
        Http::assertSentCount(4);
    }

    private function file(string $path): int
    {
        return (int) DB::table('library_files')->insertGetId([
            'path' => $path,
            'folder' => '/generated',
            'size' => 10,
            'mtime' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
