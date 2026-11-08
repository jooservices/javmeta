<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Performer;
use App\Repositories\MovieRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MovieTextSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_union_keeps_title_and_performer_hits_once_and_applies_outer_filters(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => 'Mina']);
        $title = Movie::factory()->create(['title_en' => 'Mina', 'maker' => 'A']);
        $linked = Movie::factory()->create(['title_en' => 'Unrelated', 'maker' => 'B']);
        $both = Movie::factory()->create(['title_en' => 'Mina', 'maker' => 'B']);
        $performer->movies()->attach([$linked->id, $both->id], ['source_slug' => $performer->source_slug, 'crawled_at' => now()]);
        $repo = app(MovieRepository::class);
        $page = $repo->search(['q' => 'Mina'], 'relevance', null, 20);
        $this->assertEqualsCanonicalizing([$title->id, $linked->id, $both->id], $page['items']->modelKeys());
        $this->assertSame(3, $page['total']);
        $this->assertEqualsCanonicalizing([$linked->id, $both->id], $repo->search(['q' => 'Mina', 'maker' => 'B'], 'relevance', null, 20)['items']->modelKeys());
        $this->assertSame(0, $repo->search(['q' => 'absent'], 'relevance', null, 20)['total']);
    }
}
