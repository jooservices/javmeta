<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\Support\Api\V2\FakeReadModelDatabase;
use Tests\Support\Api\V2\ReadModelRows;

/** The movie and performer lists and facets: paging, sorting and shaping run for real over canned search-table rows. */
final class CatalogueListTest extends V2TestCase
{
    private FakeReadModelDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = FakeReadModelDatabase::install();
    }

    public function test_the_movie_list_pairs_each_movie_with_its_first_cast_members_and_paginates(): void
    {
        $this->database
            ->answer('count(*) AS n FROM rm_movies', [['n' => '50']])
            ->answer('p.id = ANY', [ReadModelRows::performer(['id' => 7]), ReadModelRows::performer(['id' => 8, 'name_romaji' => 'Aoi Sola'])])
            ->answer('FROM rm_movies m WHERE', [
                ReadModelRows::movie(),
                ReadModelRows::movie(['id' => 2, 'code' => 'BBB-002', 'performer_ids' => '{8,99}']),
                ReadModelRows::movie(['id' => 3, 'code' => 'CCC-003', 'performer_ids' => '{}']),
            ]);

        $response = $this->getJson('/api/v2/movies?per_page=20&page=2')->assertOk();

        $response->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 20, 'total' => 50, 'pages' => 3]);
        self::assertSame(['AAA-001', 'BBB-002', 'CCC-003'], array_column($response->json('data'), 'code'));
        self::assertSame([7, 8], array_column($response->json('data.0.performers'), 'id'));
        self::assertSame([8], array_column($response->json('data.1.performers'), 'id'));
        self::assertSame([], $response->json('data.2.performers'));
        self::assertStringContainsString('OFFSET 20', $this->database->queries()[0]);
        self::assertStringContainsString('m.release_date DESC', $this->database->queries()[0]);
    }

    public function test_a_search_term_sorts_by_relevance_unless_a_sort_is_given_and_paging_is_clamped(): void
    {
        $this->database->answer('count(*) AS n FROM rm_movies', [['n' => 0]])->answer('FROM rm_movies m WHERE', []);

        $this->getJson('/api/v2/movies?q=abc&per_page=500&page=0')->assertOk()->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 96, 'total' => 0, 'pages' => 1]);
        self::assertStringContainsString('m.code_norm = upper', $this->database->queries()[0]);

        $this->getJson('/api/v2/movies?q=abc&sort=rating&per_page=0')->assertOk()->assertJsonPath('meta.pagination.per_page', 24);
        self::assertStringContainsString('m.score DESC', $this->database->queries()[2]);
    }

    public function test_the_performer_list_defaults_to_featured_and_shapes_each_performer(): void
    {
        $this->database
            ->answer('count(*) AS n FROM rm_performers', [['n' => 1]])
            ->answer('FROM rm_performers p WHERE', [ReadModelRows::performer()]);

        $response = $this->getJson('/api/v2/performers')->assertOk();

        $response->assertJsonPath('data.0.slug', '7-yui-hatano')->assertJsonPath('meta.pagination.per_page', 24);
        self::assertStringContainsString('p.photo_url IS NOT NULL', $this->database->queries()[0]);
    }

    public function test_performers_of_matching_movies_are_ranked_by_how_many_movies_they_share(): void
    {
        $this->database
            ->answer('count(*) AS n', [['n' => 1]])
            ->answer('c.together', [ReadModelRows::performer(['together' => 4])]);

        $response = $this->getJson('/api/v2/performers?in_movies=1&genre=Drama')->assertOk();

        $response->assertJsonPath('data.0.together', 4);
        self::assertStringContainsString('c.together DESC, p.movie_count DESC', $this->database->queries()[0]);
    }

    public function test_the_movie_facets_count_every_option_against_the_other_filters(): void
    {
        $this->database->lenient()->answer('unnest(m.genres) g WHERE', [['name' => 'Drama', 'n' => 9]]);

        $response = $this->getJson('/api/v2/movies/facets?maker=Maker')->assertOk();

        $response->assertJsonPath('data.genres.0', ['name' => 'Drama', 'n' => 9])->assertJsonPath('data.years', null);
        self::assertSame(['censored', 'uncensored'], array_keys($response->json('data.enums.censored')));
        self::assertSame(['lt60', 'mid', 'gt120'], array_keys($response->json('data.runtime')));
    }

    public function test_the_performer_facets_count_every_option_against_the_other_filters(): void
    {
        $this->database->lenient()->answer('p.cup AS name', [['name' => 'F', 'n' => 3]]);

        $response = $this->getJson('/api/v2/performers/facets?location=Tokyo')->assertOk();

        $response->assertJsonPath('data.cups.0', ['name' => 'F', 'n' => 3])->assertJsonPath('data.ages', null);
        self::assertSame(['active', 'retired'], array_keys($response->json('data.enums.status')));
    }
}
