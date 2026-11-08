<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Movie;
use App\Repositories\MovieRepository;
use Illuminate\Support\Facades\DB;

final class MovieRepositoryPostgresTest extends IntegrationTestCase
{
    public function test_postgres_text_search_ranks_results_and_advances_with_a_rank_cursor(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        Movie::factory()->create(['title_en' => 'PostgreSQL vector search']);
        Movie::factory()->create(['title_en' => 'Vector PostgreSQL archive']);
        Movie::factory()->create(['title_en' => 'Unrelated title']);
        $repository = app(MovieRepository::class);

        $first = $repository->search(['q' => 'PostgreSQL'], 'relevance', null, 1);
        self::assertSame(2, $first['total']);
        self::assertTrue($first['has_more']);
        self::assertNotNull($first['last_sort']);
        self::assertNotNull($first['last_id']);

        $second = $repository->search(['q' => 'PostgreSQL'], 'relevance', [
            'sort' => 'relevance',
            'last_sort' => $first['last_sort'],
            'last_id' => $first['last_id'],
        ], 1);
        self::assertSame(2, $second['total']);
        self::assertFalse($second['has_more']);
        self::assertCount(1, $second['items']);
        self::assertNotSame($first['items']->first()->id, $second['items']->first()->id);
        self::assertSame(2, $repository->countFiltered(['q' => 'PostgreSQL']));
    }
}
