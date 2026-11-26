<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;

/** The v2 movie and performer lists and facets against real PostgreSQL read models. */
final class CatalogueListsTest extends PostgresApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createCatalogue();
    }

    private function createCatalogue(): void
    {
        $star = Performer::factory()->create(['name_romaji' => 'Aoi Test']);
        $other = Performer::factory()->create(['name_romaji' => 'Mio Sample']);
        $a = Movie::factory()->create(['display_code' => 'AAA-001', 'title_en' => 'First alpha', 'release_date' => '2025-03-01', 'maker' => 'Maker One']);
        $b = Movie::factory()->create(['display_code' => 'BBB-002', 'title_en' => 'Second beta', 'release_date' => '2025-04-01', 'maker' => 'Maker Two']);
        MoviePerformer::factory()->create(['movie_id' => $a->id, 'performer_id' => $star->id]);
        MoviePerformer::factory()->create(['movie_id' => $b->id, 'performer_id' => $star->id]);
        MoviePerformer::factory()->create(['movie_id' => $b->id, 'performer_id' => $other->id]);
        $this->artisan('xcrawler:read-models:refresh')->assertSuccessful();
    }

    public function test_movies_are_listed_newest_first_with_their_cast_and_pagination(): void
    {
        $response = $this->getJson('/api/v2/movies?per_page=10')->assertOk();

        $response->assertJsonPath('meta.pagination.total', 2);
        self::assertSame(['BBB-002', 'AAA-001'], array_column($response->json('data'), 'code'));
        self::assertCount(2, $response->json('data.0.performers'));
    }

    public function test_the_text_filter_narrows_the_list_and_the_facet_counts_follow_the_same_filter(): void
    {
        $this->getJson('/api/v2/movies?q=alpha')->assertOk()->assertJsonPath('meta.pagination.total', 1);

        $facets = $this->getJson('/api/v2/movies/facets?maker=Maker%20One')->assertOk();
        $facets->assertJsonStructure(['data']);
    }

    public function test_performers_are_listed_and_filtered_by_the_movies_they_played_in(): void
    {
        $this->getJson('/api/v2/performers?per_page=10')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->getJson('/api/v2/performers?maker=Maker%20One')->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v2/performers/facets')->assertOk()->assertJsonStructure(['data']);
    }
}
