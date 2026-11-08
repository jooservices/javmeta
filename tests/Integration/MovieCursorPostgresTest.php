<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\IndexMovieJob;
use App\Models\Movie;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

final class MovieCursorPostgresTest extends TestCase
{
    use RefreshDatabase;

    public static function sorts(): array
    {
        return [['rating', 1], ['rating', 2], ['release_date', 1], ['release_date', 2], ['update_date', 1], ['update_date', 2]];
    }

    #[DataProvider('sorts')]
    public function test_every_movie_appears_once_in_order_across_cursor_pages(string $sort, int $perPage): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        Queue::fake([IndexMovieJob::class]);
        $key = app(ApiKeyService::class)->create('cursor regression')->plaintext;
        foreach ([9.0, 8.0, null, null, 7.5] as $index => $score) {
            Movie::factory()->create([
                'community_score' => $score,
                'release_date' => $score === null ? null : '2025-01-01',
                'updated_at' => '2026-01-01 00:00:0' . ($index % 2),
            ]);
        }
        $order = match ($sort) {
            'rating' => 'community_score DESC NULLS LAST, id DESC',
            'release_date' => 'release_date DESC NULLS FIRST, id DESC',
            'update_date' => 'updated_at DESC, id DESC',
        };
        $expected = Movie::query()->orderByRaw($order)->pluck('display_code')->all();
        $actual = [];
        $cursor = null;
        for ($page = 0; $page < 6; $page++) {
            $query = ['sort' => $sort, 'per_page' => $perPage];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $response = $this->getJson('/api/v1/movies?' . http_build_query($query), ['X-API-Key' => $key])->assertOk();
            self::assertSame(5, $response->json('meta.pagination.total'));
            array_push($actual, ...array_column($response->json('data'), 'code'));
            if (! $response->json('meta.pagination.has_more')) {
                break;
            }
            $cursor = $response->json('meta.pagination.next_cursor');
            self::assertNotEmpty($cursor);
        }
        self::assertSame($expected, $actual);
    }
}
