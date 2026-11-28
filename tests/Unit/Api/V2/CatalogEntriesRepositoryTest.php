<?php

declare(strict_types=1);

namespace Tests\Unit\Api\V2;

use App\Repositories\Api\V2\CatalogEntriesRepository;
use Tests\Support\Api\V2\FakesPostgresRows;
use Tests\TestCase;

/** The kind registry of the catalogue pages; the SELECT text itself is proven by the PostgreSQL integration tests. */
final class CatalogEntriesRepositoryTest extends TestCase
{
    use FakesPostgresRows;

    public function test_the_kinds_list_movie_kinds_before_performer_kinds(): void
    {
        $this->assertSame(['genres', 'makers', 'series', 'labels', 'years', 'cups', 'blood-types', 'locations'], (new CatalogEntriesRepository())->kinds());
    }

    public function test_only_cups_blood_types_and_locations_are_performer_kinds(): void
    {
        $repository = new CatalogEntriesRepository();

        foreach (['cups', 'blood-types', 'locations'] as $kind) {
            $this->assertTrue($repository->isPerformerKind($kind), $kind);
        }

        foreach (['genres', 'makers', 'series', 'labels', 'years', 'unknown'] as $kind) {
            $this->assertFalse($repository->isPerformerKind($kind), $kind);
        }
    }

    public function test_entries_run_the_select_of_the_kind_and_cast_the_columns(): void
    {
        $this->fakeRows([
            'FROM rm_movies m, unnest(m.genres) g' => [['name' => 'Drama', 'count' => '12', 'cover' => 'https://img/c.jpg'], ['name' => 'Solo', 'count' => 3, 'cover' => null]],
            'FROM rm_performers p WHERE p.cup IS NOT NULL' => [['name' => 'D', 'count' => '8', 'cover' => 'https://img/p.jpg']],
        ]);
        $repository = new CatalogEntriesRepository();

        $this->assertSame([['name' => 'Drama', 'count' => 12, 'cover' => 'https://img/c.jpg'], ['name' => 'Solo', 'count' => 3, 'cover' => null]], $repository->entries('genres'));
        $this->assertSame([['name' => 'D', 'count' => 8, 'cover' => 'https://img/p.jpg']], $repository->entries('cups'));
    }
}
