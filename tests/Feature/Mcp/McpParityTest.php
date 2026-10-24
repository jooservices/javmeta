<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Models\Movie;
use App\Services\Mcp\McpToolService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class McpParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_tools_are_listed(): void
    {
        $names = collect(app(McpToolService::class)->definitions())->pluck('name')->all();
        foreach (['get_catalog', 'suggest', 'get_related_movies', 'get_release_calendar'] as $tool) {
            $this->assertContains($tool, $names);
        }
    }

    public function test_catalog_suggest_and_calendar_answer_like_the_api(): void
    {
        Movie::factory()->count(2)->create(['maker' => 'Alpha', 'release_date' => '2026-10-05']);
        $tools = app(McpToolService::class);

        $this->assertSame([['name' => 'Alpha', 'movie_count' => 2]], $tools->call('get_catalog', ['kind' => 'makers'])['items']);
        $this->assertSame('invalid_filter', $tools->call('get_catalog', ['kind' => 'nope'])['error']);
        $this->assertSame([['name' => 'Alpha', 'movie_count' => 2]], $tools->call('suggest', ['q' => 'alp'])['items']['makers']);
        $this->assertSame(2, $tools->call('get_release_calendar', ['month' => '2026-10'])['meta']['total']);
        $this->assertSame('not_found', $tools->call('get_related_movies', ['code' => 'NOPE-999'])['error']);
    }
}
