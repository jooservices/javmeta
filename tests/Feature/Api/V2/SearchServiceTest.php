<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\Support\Api\V2\FakesPostgresRows;
use Tests\TestCase;

/** Search, suggestions, the photo tab and the interpretation endpoint, with the PostgreSQL rows canned (the SQL itself runs in tests/Integration). */
final class SearchServiceTest extends TestCase
{
    use FakesPostgresRows;

    /** @return array<string, list<array<string, mixed>>> */
    private function searchRows(int $movies = 2, int $performers = 1, int $photos = 1): array
    {
        return [
            'count(*) AS n FROM rm_movies m WHERE NOT m.delisted AND (m.search_text' => [['n' => $movies]],
            'FROM rm_movies m WHERE NOT m.delisted AND (m.search_text' => $movies === 0 ? [] : [$this->movieRow(), $this->movieRow(['code' => 'ABP-002'])],
            'count(*) AS n FROM rm_performers p WHERE p.search_text LIKE ? AND p.photo_url' => [['n' => $photos]],
            'count(*) AS n FROM rm_performers p WHERE p.search_text LIKE ?' => [['n' => $performers]],
            'SELECT p.id, p.name_romaji, p.photo_url FROM rm_performers p WHERE p.search_text LIKE ? AND' => $photos === 0 ? [] : [['id' => 7, 'name_romaji' => 'Yua Mikami', 'photo_url' => 'https://img/p.jpg']],
            'FROM rm_performers p WHERE p.search_text LIKE ? ORDER BY' => $performers === 0 ? [] : [$this->performerRow()],
        ];
    }

    public function test_a_search_returns_the_three_groups_with_their_totals(): void
    {
        $this->fakeRows($this->searchRows());

        $response = $this->getJson('/api/v2/search?q=Yua');

        $response->assertOk()
            ->assertJsonPath('data.movies.total', 2)
            ->assertJsonPath('data.movies.rows.0.code', 'ABP-001')
            ->assertJsonPath('data.movies.rows.1.code', 'ABP-002')
            ->assertJsonPath('data.performers.total', 1)
            ->assertJsonPath('data.performers.rows.0.slug', '7-yua-mikami')
            ->assertJsonPath('data.photos.total', 1)
            ->assertJsonPath('data.photos.rows.0', ['kind' => 'avatar', 'thumb_url' => 'https://img/p.jpg', 'full_url' => 'https://img/p.jpg', 'label' => 'Yua Mikami', 'performer_id' => 7])
            ->assertJsonPath('meta.query', 'Yua')
            ->assertJsonPath('meta.did_you_mean', null)
            ->assertJsonPath('meta.looks_like_code', false);
    }

    public function test_page_and_per_page_become_limit_and_offset_and_are_clamped(): void
    {
        $this->fakeRows($this->searchRows());

        $this->getJson('/api/v2/search?q=Yua&page=3&per_page=5&types=movie')->assertOk();
        $this->assertStringContainsString('LIMIT 5 OFFSET 10', $this->sqlContaining('ORDER BY (m.code_norm = ?)')[0]['sql']);

        $this->getJson('/api/v2/search?q=Yua&per_page=500&page=0&types=movie')->assertOk();
        $this->assertStringContainsString('LIMIT 48 OFFSET 0', $this->sqlContaining('ORDER BY (m.code_norm = ?)')[1]['sql']);
    }

    public function test_only_the_asked_types_are_searched_and_the_others_are_null(): void
    {
        $this->fakeRows($this->searchRows());

        $this->getJson('/api/v2/search?q=Yua&types=movie, bogus')
            ->assertOk()
            ->assertJsonPath('data.movies.total', 2)
            ->assertJsonPath('data.performers', null)
            ->assertJsonPath('data.photos', null);
        $this->assertSame([], $this->sqlContaining('FROM rm_performers'));
    }

    public function test_a_search_without_hits_offers_the_closest_name(): void
    {
        $this->fakeRows($this->searchRows(0, 0, 0) + ['similarity(' => [['s' => 'Yua Mikami']]]);

        $this->getJson('/api/v2/search?q=Yuaa%20Mikamy')
            ->assertOk()
            ->assertJsonPath('data.movies.total', 0)
            ->assertJsonPath('meta.did_you_mean', 'Yua Mikami');
        $this->assertSame(['yuaa mikamy', 'yuaa mikamy', 'yuaa mikamy', 'yuaa mikamy'], $this->sqlContaining('similarity(')[0]['bindings']);
    }

    public function test_a_search_without_hits_and_without_a_close_name_has_no_suggestion(): void
    {
        $this->fakeRows($this->searchRows(0, 0, 0) + ['similarity(' => []]);

        $this->getJson('/api/v2/search?q=zzzzzz')->assertOk()->assertJsonPath('meta.did_you_mean', null);
    }

    public function test_a_hit_in_any_group_means_no_suggestion_is_needed(): void
    {
        $this->fakeRows($this->searchRows(0, 1, 0));

        $this->getJson('/api/v2/search?q=Yua')->assertOk()->assertJsonPath('meta.did_you_mean', null);
        $this->assertSame([], $this->sqlContaining('similarity('));
    }

    public function test_a_text_that_looks_like_a_product_code_is_flagged(): void
    {
        $this->fakeRows($this->searchRows());

        foreach (['ABP-123' => true, 'abp123' => true, 'ssis00123' => true, 'hello' => false, 'a-123' => false, 'ABP-1' => false, 'toolong-123' => false] as $text => $expected) {
            $this->getJson('/api/v2/search?q=' . $text)->assertOk()->assertJsonPath('meta.looks_like_code', $expected);
        }
    }

    public function test_the_movie_query_ignores_punctuation_when_matching_a_code(): void
    {
        $this->fakeRows($this->searchRows());

        $this->getJson('/api/v2/search?q=abp-123&types=movie')->assertOk();

        $this->assertSame(['%abp-123%', 'ABP123', 'ABP123'], $this->sqlContaining('ORDER BY (m.code_norm = ?)')[0]['bindings']);
    }

    public function test_suggestions_are_shaped_for_the_dropdown(): void
    {
        $this->fakeRows([
            'FROM rm_movies WHERE NOT delisted AND (code ILIKE' => [
                ['code' => 'ABP-001', 'title_en' => 'English', 'title_jp' => 'JP', 'cover_thumb_url' => 'https://img/t.jpg', 'cover_url' => 'https://img/c.jpg'],
                ['code' => 'ABP-002', 'title_en' => '', 'title_jp' => 'JP only', 'cover_thumb_url' => '', 'cover_url' => 'https://img/c2.jpg'],
            ],
            'FROM rm_performers WHERE search_text LIKE' => [['id' => 7, 'name_romaji' => 'Yua Mikami', 'name_kanji' => '三上悠亜', 'photo_url' => null, 'movie_count' => '12']],
            'SELECT maker AS name' => [['name' => 'Moodyz Diva', 'count' => '30']],
            'SELECT series AS name' => [['name' => 'Night Shift', 'count' => 4]],
            'SELECT g AS name' => [['name' => 'Big Tits', 'count' => 9]],
        ]);

        $this->getJson('/api/v2/suggestions?q=%20yu%20')
            ->assertOk()
            ->assertJsonPath('data.movies.0', ['code' => 'ABP-001', 'title' => 'English', 'cover_url' => 'https://img/t.jpg'])
            ->assertJsonPath('data.movies.1', ['code' => 'ABP-002', 'title' => 'JP only', 'cover_url' => 'https://img/c2.jpg'])
            ->assertJsonPath('data.performers.0', ['id' => 7, 'slug' => '7-yua-mikami', 'name' => 'Yua Mikami', 'name_kanji' => '三上悠亜', 'photo_url' => null, 'movie_count' => 12])
            ->assertJsonPath('data.makers.0', ['name' => 'Moodyz Diva', 'count' => 30, 'slug' => 'moodyz-diva'])
            ->assertJsonPath('data.series.0.slug', 'night-shift')
            ->assertJsonPath('data.genres.0.slug', 'big-tits');
    }

    public function test_the_suggestion_limit_defaults_to_six_and_never_goes_above_ten(): void
    {
        $this->fakeRows([
            'FROM rm_movies WHERE NOT delisted AND (code ILIKE' => [],
            'FROM rm_performers WHERE search_text LIKE' => [],
            'SELECT maker AS name' => [],
            'SELECT series AS name' => [],
            'SELECT g AS name' => [],
        ]);

        $this->getJson('/api/v2/suggestions?q=yu')->assertOk();
        $this->getJson('/api/v2/suggestions?q=yu&limit=0')->assertOk();
        $this->getJson('/api/v2/suggestions?q=yu&limit=99')->assertOk();
        $this->getJson('/api/v2/suggestions?q=yu&limit=-4')->assertOk();

        $limits = array_map(static fn(array $call): string => substr($call['sql'], (int) strrpos($call['sql'], 'LIMIT')), $this->sqlContaining('FROM rm_performers WHERE search_text LIKE'));
        $this->assertSame(['LIMIT 6', 'LIMIT 6', 'LIMIT 10', 'LIMIT 1'], $limits);
    }

    public function test_a_single_character_suggestion_never_reaches_the_database(): void
    {
        $this->fakeRows([]);

        $this->getJson('/api/v2/suggestions?q=y')->assertOk()->assertJsonPath('data.genres', []);
        $this->assertSame([], $this->sqlCalls);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function photoRows(): array
    {
        return [
            'count(*) AS n FROM rm_movies m WHERE' => [['n' => 40]],
            'SELECT m.code, m.cover_url' => [
                ['code' => 'ABP-001', 'cover_url' => 'https://img/c.jpg', 'cover_thumb_url' => 'https://img/t.jpg', 'gallery_count' => 2],
                ['code' => 'ABP-002', 'cover_url' => 'https://img/c2.jpg', 'cover_thumb_url' => null, 'gallery_count' => 0],
            ],
            'unnest(m.performer_ids)' => [['id' => 7, 'name_romaji' => 'Yua Mikami', 'photo_url' => 'https://img/p.jpg']],
        ];
    }

    public function test_the_photo_tab_lists_covers_and_the_cast_on_the_first_page(): void
    {
        $this->fakeRows($this->photoRows());

        $this->getJson('/api/v2/search/photos?genre=Drama')
            ->assertOk()
            ->assertJsonPath('data.total', 41)
            ->assertJsonPath('data.people.0', ['kind' => 'avatar', 'src' => 'https://img/p.jpg', 'full' => 'https://img/p.jpg', 'to' => '/performers/7-yua-mikami', 'label' => 'Yua Mikami', 'performer_id' => 7])
            ->assertJsonPath('data.covers.0', ['kind' => 'cover', 'src' => 'https://img/t.jpg', 'full' => 'https://img/c.jpg', 'to' => '/movies/ABP-001', 'label' => 'ABP-001', 'movie_code' => 'ABP-001'])
            ->assertJsonPath('data.covers.1.src', 'https://img/c2.jpg')
            ->assertJsonPath('meta.page', 1)
            ->assertJsonPath('meta.per_page', 48);
    }

    public function test_the_photo_tab_has_no_people_after_the_first_page_or_when_a_performer_filter_is_set(): void
    {
        $this->fakeRows($this->photoRows());

        $this->getJson('/api/v2/search/photos?page=2&per_page=10')->assertOk()->assertJsonPath('data.people', [])->assertJsonPath('data.total', 40)->assertJsonPath('meta.per_page', 10);
        $this->getJson('/api/v2/search/photos?cup=F')->assertOk()->assertJsonPath('data.people', []);
        $this->getJson('/api/v2/search/photos?cup=&per_page=1000')->assertOk()->assertJsonPath('data.people.0.performer_id', 7)->assertJsonPath('meta.per_page', 120);
    }

    public function test_the_catalogue_names_matching_a_text_are_capped_at_24(): void
    {
        $many = static fn(string $prefix): array => array_map(static fn(int $i): array => ['name' => "{$prefix} {$i}", 'n' => $i], range(1, 8));
        $this->fakeRows([
            'unnest(genres) g WHERE lower(g) LIKE ANY' => $many('genre'),
            'lower(maker) LIKE ANY' => $many('maker'),
            'lower(series) LIKE ANY' => $many('series'),
            'lower(label) LIKE ANY' => $many('label'),
        ]);

        $response = $this->getJson('/api/v2/search/catalog?q=' . rawurlencode('Big Tits 50%'));

        $response->assertOk();
        $this->assertCount(24, $response->json('data'));
        $this->assertSame(['kind' => 'genres', 'name' => 'genre 1', 'n' => 1], $response->json('data.0'));
        $this->assertSame('{"%big tits 50\\\\%%","%tits%"}', $this->sqlContaining('lower(maker)')[0]['bindings'][0]);
    }

    public function test_a_catalogue_text_with_one_character_is_empty_even_after_trimming(): void
    {
        $this->fakeRows([]);

        $this->getJson('/api/v2/search/catalog?q=%20a%20')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/search/catalog')->assertOk()->assertJsonPath('data', []);
        $this->assertSame([], $this->sqlCalls);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function interpretationRows(): array
    {
        return [
            'unnest(m.genres) g' => [['name' => 'Drama', 'n' => 500]],
            'maker IS NOT NULL' => [],
            'series IS NOT NULL' => [],
            'location IS NOT NULL' => [['name' => 'Tokyo', 'n' => 90]],
            'lower(name_romaji) = ANY' => [],
        ];
    }

    public function test_the_interpretation_of_a_movie_question_lists_what_was_understood(): void
    {
        $this->fakeRows($this->interpretationRows());

        $this->postJson('/api/v2/search/interpretations', ['q' => ' drama 2022 dưới 90 phút '])
            ->assertOk()
            ->assertJsonPath('data.query', 'drama 2022 dưới 90 phút')
            ->assertJsonPath('data.filters', ['released_from' => '2022', 'released_to' => '2022', 'runtime_max' => '90', 'genre' => 'Drama'])
            ->assertJsonPath('data.conditions.0.id', 'Năm:2022')
            ->assertJsonPath('data.ignored', [])
            ->assertJsonPath('data.residual', '');
    }

    public function test_the_interpretation_can_switch_conditions_off(): void
    {
        $this->fakeRows($this->interpretationRows());

        $this->postJson('/api/v2/search/interpretations', ['q' => 'drama 2022', 'ignore' => ['Năm:2022']])
            ->assertOk()
            ->assertJsonPath('data.filters', ['genre' => 'Drama'])
            ->assertJsonPath('data.ignored.0.id', 'Năm:2022');
    }

    public function test_the_interpretation_of_a_performer_question_reads_the_performer_rules(): void
    {
        $this->fakeRows($this->interpretationRows());

        $this->postJson('/api/v2/search/interpretations', ['q' => 'nữ 25 tuổi cup D ở Tokyo', 'target' => 'performers'])
            ->assertOk()
            ->assertJsonPath('data.filters', ['age_min' => '25', 'age_max' => '25', 'cup' => 'D', 'location' => 'Tokyo']);
    }

    public function test_an_interpretation_that_understood_nothing_answers_an_empty_object_for_the_filters(): void
    {
        $this->fakeRows($this->interpretationRows());

        $content = (string) $this->postJson('/api/v2/search/interpretations', ['q' => 'x', 'target' => 'movies'])->assertOk()->getContent();

        $this->assertStringContainsString('"filters":{}', $content);
        $this->assertStringContainsString('"conditions":[]', $content);
    }
}
