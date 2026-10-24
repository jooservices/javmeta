<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Movie;
use App\Models\Performer;
use App\Repositories\PerformerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PerformerListCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_name_pages_batch_counts_without_changing_order_totals_or_cursor(): void
    {
        $a = Performer::factory()->create(['name_romaji' => 'Aiko']);
        $b = Performer::factory()->create(['name_romaji' => 'Bella']);
        Performer::factory()->create(['name_romaji' => 'Cara']);
        $a->movies()->attach(Movie::factory()->count(3)->create()->modelKeys(), ['source_slug' => $a->source_slug, 'crawled_at' => now()]);
        $repo = app(PerformerRepository::class);
        DB::enableQueryLog();
        $page = $repo->search([], 'name', null, 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame([$a->id, $b->id], $page['items']->modelKeys());
        $this->assertSame([3, 0], $page['items']->pluck('movies_count')->all());
        $this->assertSame(3, $page['total']);
        $this->assertTrue($page['has_more']);
        $this->assertSame('Bella', $page['last_sort']);
        $countQueries = array_values(array_filter($queries, static fn(array $q): bool => str_contains($q['query'], 'movie_performers')));
        $this->assertCount(1, $countQueries);
        $this->assertStringContainsString('group by', $countQueries[0]['query']);
        $this->assertSame([$a->id], $repo->search(['q' => 'Aiko'], 'name', null, 2)['items']->modelKeys());
        $next = $repo->search([], 'name', ['sort' => 'name', 'last_sort' => $page['last_sort'], 'last_id' => $page['last_id']], 2);
        $this->assertSame(['Cara'], $next['items']->pluck('name_romaji')->all());
        $this->assertSame(0, $next['items'][0]->movies_count);
        $this->assertFalse($next['has_more']);
    }

    public function test_count_sort_and_empty_page_keep_the_existing_results(): void
    {
        $a = Performer::factory()->create();
        $b = Performer::factory()->create();
        $a->movies()->attach(Movie::factory()->count(2)->create()->modelKeys(), ['source_slug' => $a->source_slug, 'crawled_at' => now()]);
        $b->movies()->attach(Movie::factory()->create()->id, ['source_slug' => $b->source_slug, 'crawled_at' => now()]);
        $repo = app(PerformerRepository::class);
        $this->assertSame([$a->id, $b->id], $repo->search([], 'linked_title_count', null, 2)['items']->modelKeys());
        $this->assertCount(0, $repo->search(['q' => 'no-such-name'], 'name', null, 2)['items']);
    }
}
