<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Jobs\IndexMovieJob;
use App\Jobs\IndexPerformerJob;
use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Services\Auth\ApiKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

final class PerformerCursorPostgresTest extends TestCase
{
    use RefreshDatabase;

    public static function sizes(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('sizes')]
    public function test_count_cursor_pages_include_ties_and_zero_counts(int $perPage): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        Queue::fake([IndexMovieJob::class, IndexPerformerJob::class]);
        $key = app(ApiKeyService::class)->create('performer cursor regression')->plaintext;
        foreach ([2, 0, 2, 1, 0] as $count) {
            $performer = Performer::factory()->create(['canonical_performer_id' => null]);
            foreach (Movie::factory()->count($count)->create() as $movie) {
                MoviePerformer::factory()->create(['movie_id' => $movie->id, 'performer_id' => $performer->id]);
            }
        }
        // Canonical redirects must stay excluded from the derived-table query.
        Performer::factory()->create(['canonical_performer_id' => $performer->id]);
        $expected = Performer::query()->whereNull('canonical_performer_id')->withCount('movies')
            ->orderByDesc('movies_count')->orderByDesc('id')->get()->modelKeys();
        $actual = [];
        $cursor = null;
        for ($page = 0; $page < 6; $page++) {
            $query = ['sort' => 'linked_title_count', 'per_page' => $perPage];
            if ($cursor !== null) {
                $query['cursor'] = $cursor;
            }
            $response = $this->getJson('/api/v1/performers?' . http_build_query($query), ['X-API-Key' => $key])->assertOk();
            self::assertSame(5, $response->json('meta.pagination.total'));
            array_push($actual, ...array_column($response->json('data'), 'id'));
            if (! $response->json('meta.pagination.has_more')) {
                break;
            }
            $cursor = $response->json('meta.pagination.next_cursor');
            self::assertNotEmpty($cursor);
        }
        self::assertSame($expected, $actual);
    }
}
