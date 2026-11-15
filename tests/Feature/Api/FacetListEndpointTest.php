<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Services\Auth\ApiKeyService;
use App\Services\Dataset\SampleDataset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FacetListEndpointTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        app(SampleDataset::class)->load();
        $this->headers = ['X-API-Key' => app(ApiKeyService::class)->create('facet-list', 60, ['movies', 'performers', 'catalog'])->plaintext];
    }

    public function test_movie_facet_values_are_paginated_searchable_and_sorted(): void
    {
        $page1 = $this->getJson('/api/v1/movies/facets/values?facet=genres&per_page=10&page=1&sort=count&min_count=0', $this->headers)
            ->assertOk()
            ->json();

        $this->assertSame('genres', $page1['data']['facet']);
        $this->assertSame(SampleDataset::GENRES, $page1['data']['total']);
        $this->assertCount(10, $page1['data']['items']);
        $this->assertSame(1, $page1['meta']['pagination']['page']);
        $this->assertSame(10, $page1['meta']['pagination']['per_page']);
        $this->assertGreaterThan(1, $page1['meta']['pagination']['last_page']);
        $this->assertSame(SampleDataset::GENRES, $page1['meta']['pagination']['total']);

        $counts = array_column($page1['data']['items'], 'count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, 'default sort is by count descending');

        $page2 = $this->getJson('/api/v1/movies/facets/values?facet=genres&per_page=10&page=2&sort=count&min_count=0', $this->headers)
            ->assertOk()
            ->json('data');
        $this->assertNotSame(
            array_column($page1['data']['items'], 'name'),
            array_column($page2['items'], 'name'),
        );

        $search = $this->getJson('/api/v1/movies/facets/values?facet=genres&q=Sample%20Genre%20001&min_count=0', $this->headers)
            ->assertOk()
            ->json('data');
        $this->assertSame(['Sample Genre 001'], array_column($search['items'], 'name'));

        $byName = $this->getJson('/api/v1/movies/facets/values?facet=makers&sort=name&min_count=0&per_page=5', $this->headers)
            ->assertOk()
            ->json('data.items');
        $names = array_column($byName, 'name');
        $sortedNames = $names;
        sort($sortedNames);
        $this->assertSame($sortedNames, $names);

        $this->getJson('/api/v1/movies/facets/values?facet=genres')->assertUnauthorized();
        $this->getJson('/api/v1/movies/facets/values?facet=nope', $this->headers)->assertStatus(400);
        $this->getJson('/api/v1/movies/facets/values?facet=genres&per_page=999', $this->headers)->assertStatus(400);
    }

    public function test_movie_facet_min_count_hides_noisy_labels_and_scopes_to_filters(): void
    {
        $all = $this->getJson('/api/v1/movies/facets/values?facet=genres&min_count=0&per_page=200', $this->headers)
            ->assertOk()
            ->json('data');
        $filtered = $this->getJson('/api/v1/movies/facets/values?facet=genres&min_count=3&per_page=200', $this->headers)
            ->assertOk()
            ->json('data');

        $this->assertLessThanOrEqual($all['total'], $filtered['total'] + $filtered['hidden_below_min']);
        $this->assertGreaterThanOrEqual(0, $filtered['hidden_below_min']);

        $narrow = $this->getJson(
            '/api/v1/movies/facets/values?facet=makers&min_count=0&maker=' . urlencode('Sample Maker 03'),
            $this->headers,
        )->assertOk()->json('data');
        $this->assertSame(['Sample Maker 03'], array_column($narrow['items'], 'name'));
    }

    public function test_performer_facet_values_are_paginated(): void
    {
        $body = $this->getJson('/api/v1/performers/facets/values?facet=cups&min_count=0&per_page=20', $this->headers)
            ->assertOk()
            ->json();
        $this->assertSame('cups', $body['data']['facet']);
        $this->assertNotEmpty($body['data']['items']);
        $this->assertArrayHasKey('pagination', $body['meta']);
        $this->getJson('/api/v1/performers/facets/values?facet=cups')->assertUnauthorized();
    }
}
