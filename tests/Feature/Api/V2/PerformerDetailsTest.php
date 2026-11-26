<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\Support\Api\V2\FakeReadModelDatabase;
use Tests\Support\Api\V2\ReadModelRows;
use Tests\TestCase;

/** One performer over the v2 API: profile, sources, photos, co-performers, statistics, movies, lookups and facets, over canned rows. */
final class PerformerDetailsTest extends TestCase
{
    private FakeReadModelDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = FakeReadModelDatabase::install();
    }

    public function test_the_profile_merges_the_summary_the_bio_and_the_profile_fields_of_the_attributes(): void
    {
        $attrs = [
            'extra' => ['hobby' => 'Cooking', 'special_skill' => 'Piano', 'agency' => 'Agency', 'rating_looks' => 9, 'rating_body' => 8, 'movie_count' => '15', 'other' => 'Note'],
            'zodiac_sign' => 'Gemini', 'hair_color' => 'Black', 'favorite_count_raw' => '120', 'tags' => ['a', 'b'],
        ];
        $this->database->answer('p.bio_text', [ReadModelRows::performer(['movie_count' => 1, 'n_movies' => '12', 'bio_text' => 'Bio', 'attrs' => json_encode($attrs, JSON_THROW_ON_ERROR)])]);

        $response = $this->getJson('/api/v2/performers/7-yui-hatano')->assertOk();

        $response->assertJsonPath('meta.canonical_slug', '7-yui-hatano')->assertJsonPath('data.movie_count', 12)->assertJsonPath('data.bio_text', 'Bio');
        $response->assertJsonPath('data.profile.hobby', 'Cooking')->assertJsonPath('data.profile.skill', 'Piano')->assertJsonPath('data.profile.note', 'Note')->assertJsonPath('data.profile.zodiac', 'Gemini');
        $response->assertJsonPath('data.profile.favorite_count', 120)->assertJsonPath('data.profile.tags', ['a', 'b'])->assertJsonPath('data.profile.filmography_count', 15);
        $response->assertJsonPath('data.profile.ratings', ['looks' => 9, 'body' => 8])->assertJsonPath('data.profile.agency', 'Agency')->assertJsonPath('data.profile.nickname', null);
    }

    public function test_a_profile_without_attributes_has_empty_profile_fields(): void
    {
        $this->database->answer('p.bio_text', [ReadModelRows::performer(['n_movies' => 0, 'bio_text' => null, 'attrs' => null])]);

        $response = $this->getJson('/api/v2/performers/7')->assertOk();

        $response->assertJsonPath('data.profile.favorite_count', null)->assertJsonPath('data.profile.tags', [])->assertJsonPath('data.profile.filmography_count', null);
        self::assertStringContainsString('"ratings":{}', (string) $response->getContent());
    }

    public function test_a_favourite_count_of_zero_is_reported_as_unknown(): void
    {
        $this->database->answer('p.bio_text', [ReadModelRows::performer(['n_movies' => 0, 'bio_text' => null, 'attrs' => '{"favorite_count_raw":"0"}'])]);

        $this->getJson('/api/v2/performers/7')->assertOk()->assertJsonPath('data.profile.favorite_count', null);
    }

    public function test_an_unknown_or_malformed_performer_is_404_on_every_sub_resource(): void
    {
        $this->database->answer('p.bio_text', [])->answer('SELECT 1 AS found', []);

        foreach (['/api/v2/performers/9', '/api/v2/performers/9/sources'] as $url) {
            $this->getJson($url)->assertNotFound()->assertJsonPath('code', 'performer_not_found');
        }

        foreach (['', '/sources', '/photos', '/co-performers', '/statistics', '/movies'] as $suffix) {
            $this->getJson('/api/v2/performers/abc' . $suffix)->assertNotFound()->assertJsonPath('code', 'performer_not_found')->assertJsonPath('detail', 'No performer abc.');
        }
    }

    public function test_sources_combine_the_source_records_with_merged_rows_not_already_listed(): void
    {
        $record = ['id' => 1, 'source' => 'alpha', 'external_id' => 'e1', 'url' => 'https://a/1', 'name_romaji' => 'Yui', 'name_kanji' => null, 'birth_date' => '1988-05-24', 'crawled_at' => '2024-05-02 10:00:00', 'active' => true, 'removed_at' => null];
        $this->database
            ->answer('SELECT 1 AS found', [['found' => 1]])
            ->answer('FROM performer_sources', [$record, ['id' => 2, 'source' => 'beta', 'url' => 'https://b/2', 'active' => false, 'removed_at' => '2024-06-01 00:00:00'] + $record])
            ->answer('FROM performers WHERE canonical', [$record, ['id' => 3, 'source' => 'gamma', 'url' => 'https://c/3'] + $record]);

        $response = $this->getJson('/api/v2/performers/7/sources')->assertOk();

        self::assertSame([1, 2, 3], array_column($response->json('data'), 'id'));
        $response->assertJsonPath('data.1.active', false)->assertJsonPath('data.1.removed_at', date('c', (int) strtotime('2024-06-01 00:00:00')))->assertJsonPath('data.0.removed_at', null);
    }

    public function test_photos_are_listed_by_kind(): void
    {
        $this->database->answer('FROM performer_media', [['kind' => 'image', 'url' => 'https://img/1', 'source' => 'alpha'], ['kind' => 'gallery', 'url' => 'https://img/2', 'source' => null]]);

        $this->getJson('/api/v2/performers/7-yui/photos')->assertOk()->assertExactJson(['data' => [['kind' => 'image', 'url' => 'https://img/1', 'source' => 'alpha'], ['kind' => 'gallery', 'url' => 'https://img/2', 'source' => null]], 'meta' => []]);
    }

    public function test_co_performers_carry_the_shared_movie_count_and_the_limit_is_clamped(): void
    {
        $this->database->answer('c.n AS together', [ReadModelRows::performer(['id' => 8, 'together' => 6])]);

        $this->getJson('/api/v2/performers/7/co-performers')->assertOk()->assertJsonPath('data.0.together', 6);
        self::assertStringContainsString('LIMIT 12)', $this->database->queries()[0]);

        $this->getJson('/api/v2/performers/7/co-performers?limit=500')->assertOk();
        self::assertStringContainsString('LIMIT 30)', $this->database->queries()[1]);
    }

    public function test_statistics_are_read_once_and_then_served_from_the_cache(): void
    {
        $this->database
            ->answer('GROUP BY year', [['year' => '2023', 'count' => '4']])
            ->answer('maker AS name', [['name' => 'Maker', 'count' => '3']])
            ->answer('g AS name', [['name' => 'Drama', 'count' => '2']])
            ->answer('min(release_date)', [['first' => '2010-01-01', 'last' => '2024-01-01', 'total' => '12']]);

        $first = $this->getJson('/api/v2/performers/7/statistics')->assertOk();
        $queries = count($this->database->queries());
        $second = $this->getJson('/api/v2/performers/7/statistics')->assertOk();

        $first->assertJsonPath('data.by_year', [['year' => 2023, 'count' => 4]])->assertJsonPath('data.top_makers.0.name', 'Maker')->assertJsonPath('data.top_genres.0.count', 2)->assertJsonPath('data.span.total', 12);
        self::assertSame($first->json(), $second->json());
        self::assertSame($queries, count($this->database->queries()));
    }

    public function test_the_movies_of_a_performer_are_paged_and_sorted(): void
    {
        $this->database->answer('count(*) AS n FROM rm_movies', [['n' => 30]])->answer('FROM rm_movies m WHERE', [ReadModelRows::movie()]);

        $response = $this->getJson('/api/v2/performers/7/movies?sort=score&per_page=10&page=2')->assertOk();

        $response->assertJsonPath('data.0.code', 'AAA-001')->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 10, 'total' => 30, 'pages' => 3]);
        self::assertStringContainsString('m.score ASC NULLS LAST', $this->database->queries()[0]);
        self::assertStringContainsString('OFFSET 10', $this->database->queries()[0]);

        $this->getJson('/api/v2/performers/7/movies')->assertOk();
        self::assertStringContainsString('m.release_date DESC NULLS LAST', $this->database->queries()[2]);
        self::assertStringContainsString('LIMIT 12', $this->database->queries()[2]);

        $this->getJson('/api/v2/performers/7/movies?sort=popularity')->assertStatus(422)->assertJsonFragment(['field' => 'sort']);
    }

    public function test_lookups_return_each_known_performer_once_in_request_order(): void
    {
        $this->database->answer('p.id = ANY', [ReadModelRows::performer(['id' => 7]), ReadModelRows::performer(['id' => 8])]);

        $response = $this->postJson('/api/v2/performers/lookups', ['ids' => [8, 99, 7, 8]])->assertOk();

        self::assertSame([8, 7], array_column($response->json('data'), 'id'));
    }

    public function test_facet_values_autocomplete_and_refuse_unknown_facets(): void
    {
        $this->database->answer('FROM rm_performers WHERE', [['value' => 'Tokyo', 'count' => '5']]);

        $this->getJson('/api/v2/performers/facets/values?facet=location&q=tok')->assertOk()->assertExactJson(['data' => [['value' => 'Tokyo', 'count' => 5]], 'meta' => []]);
        $this->getJson('/api/v2/performers/facets/values?facet=height')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
        $this->getJson('/api/v2/performers/facets/values')->assertStatus(400)->assertJsonPath('code', 'invalid_facet');
    }

    public function test_the_movie_side_facets_are_counted_in_distinct_performers(): void
    {
        $this->database->lenient()->answer('FROM rm_perf_attr a', [['name' => 'Drama', 'n' => 7]]);

        $response = $this->getJson('/api/v2/performers/movie-facets')->assertOk();

        $response->assertJsonPath('data.genres.0', ['name' => 'Drama', 'n' => 7])->assertJsonPath('data.years', null);

        $this->getJson('/api/v2/performers/movie-facets?maker=Maker&genre=Drama')->assertOk()->assertJsonPath('data.makers', []);
    }
}
