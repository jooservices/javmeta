<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Movie;
use App\Models\MovieSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MovieSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawling_the_same_source_again_updates_the_row_instead_of_adding_a_second_one(): void
    {
        $movie = Movie::factory()->create();

        MovieSource::recordArchive($movie->id, 'javbtc', 'archive-1', 'https://javbtc.test/a', 'AAA-001', now()->subHour());
        MovieSource::recordArchive($movie->id, 'javbtc', 'archive-2', 'https://javbtc.test/a2', 'AAA-001', now());

        $this->assertSame(1, MovieSource::query()->where('movie_id', $movie->id)->count());
        $row = MovieSource::query()->where('movie_id', $movie->id)->firstOrFail();
        $this->assertSame('archive-2', $row->mongo_id);
        $this->assertSame('https://javbtc.test/a2', $row->source_url);
    }

    public function test_the_same_movie_from_two_sources_keeps_one_row_per_source(): void
    {
        $movie = Movie::factory()->create();

        MovieSource::recordArchive($movie->id, 'javbtc', 'a', null, null, now());
        MovieSource::recordArchive($movie->id, 'javdb', 'b', null, null, now());

        $this->assertSame(2, MovieSource::query()->where('movie_id', $movie->id)->count());
    }
}
