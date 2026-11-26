<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V2;

use Tests\Support\Api\V2\FakeReadModelDatabase;
use Tests\TestCase;

/** The browsable catalogues and the photo collection over canned rows: filtering, ordering, paging, script index, slugs, 404s. */
final class CatalogEntriesTest extends TestCase
{
    private FakeReadModelDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = FakeReadModelDatabase::install();
        $this->database->answer('SELECT g AS name', [
            ['name' => 'Drama', 'count' => 5, 'cover' => 'https://img/d.jpg'],
            ['name' => 'Comedy', 'count' => 5, 'cover' => null],
            ['name' => 'Action', 'count' => 9, 'cover' => null],
            ['name' => 'ドラマ', 'count' => 2, 'cover' => null],
            ['name' => 'ｱｸｼｮﾝ', 'count' => 1, 'cover' => null],
            ['name' => '中文', 'count' => 1, 'cover' => null],
            ['name' => 'Éclair Cut', 'count' => 3, 'cover' => null],
            ['name' => '!!', 'count' => 4, 'cover' => null],
            ['name' => '', 'count' => 1, 'cover' => null],
        ])->answer('p.cup AS name', [['name' => 'F', 'count' => 10, 'cover' => 'https://img/p.jpg']]);
    }

    /** @return list<string> */
    private function names(string $url): array
    {
        return array_column($this->getJson($url)->assertOk()->json('data'), 'name');
    }

    public function test_the_kinds_name_the_target_and_filter_of_each_catalogue(): void
    {
        $response = $this->getJson('/api/v2/catalogs')->assertOk();

        self::assertCount(8, $response->json('data'));
        $response->assertJsonPath('data.0', ['kind' => 'genres', 'target' => 'movies', 'filter' => 'genre'])->assertJsonPath('data.5', ['kind' => 'cups', 'target' => 'performers', 'filter' => 'cup']);
    }

    public function test_an_unknown_catalogue_is_404_before_any_query_runs(): void
    {
        $this->getJson('/api/v2/catalogs/nope')->assertNotFound()->assertJsonPath('code', 'catalog_not_found')->assertJsonPath('detail', 'Unknown catalogue nope.');
        self::assertSame([], $this->database->queries());
    }

    public function test_entries_default_to_the_most_used_first_and_carry_script_target_and_filter(): void
    {
        $response = $this->getJson('/api/v2/catalogs/genres')->assertOk();

        self::assertSame('Action', $response->json('data.0.name'));
        $response->assertJsonPath('data.0', ['slug' => 'action', 'name' => 'Action', 'count' => 9, 'cover_url' => null, 'script' => 'latin', 'kind' => 'movies', 'filter' => 'genre']);
        $response->assertJsonPath('meta.pagination.total', 9)->assertJsonPath('meta.total_entries', 9);
    }

    public function test_entries_can_be_ordered_by_count_or_name_in_both_directions(): void
    {
        self::assertSame(['Action', 'Drama', 'Comedy'], array_slice($this->names('/api/v2/catalogs/genres?sort=-count'), 0, 3));
        self::assertSame(['', '中文', 'ｱｸｼｮﾝ'], array_slice($this->names('/api/v2/catalogs/genres?sort=count'), 0, 3));
        self::assertSame(['', '!!', 'Action'], array_slice($this->names('/api/v2/catalogs/genres?sort=name'), 0, 3));
        self::assertSame('ｱｸｼｮﾝ', $this->names('/api/v2/catalogs/genres?sort=-name')[0]);
    }

    public function test_entries_filter_by_script_minimum_count_search_term_and_first_letter(): void
    {
        self::assertSame(['ドラマ', 'ｱｸｼｮﾝ'], $this->names('/api/v2/catalogs/genres?script=ja&sort=-count'));
        self::assertSame(['中文'], $this->names('/api/v2/catalogs/genres?script=zh'));
        self::assertSame(['Action', 'Drama', 'Comedy'], array_slice($this->names('/api/v2/catalogs/genres?min_count=5'), 0, 3));
        self::assertSame(5, count($this->names('/api/v2/catalogs/genres?min_count=3')));
        self::assertSame(['Comedy'], $this->names('/api/v2/catalogs/genres?q=%20COME'));
        self::assertSame(['Action'], $this->names('/api/v2/catalogs/genres?letter=a'));
    }

    public function test_entries_are_paged_with_the_pagination_of_the_filtered_set(): void
    {
        $response = $this->getJson('/api/v2/catalogs/genres?per_page=4&page=3')->assertOk();

        self::assertCount(1, $response->json('data'));
        $response->assertJsonPath('meta.pagination', ['page' => 3, 'per_page' => 4, 'total' => 9, 'pages' => 3]);
    }

    public function test_a_symbol_only_name_keeps_its_name_as_slug_and_accents_are_folded(): void
    {
        $slugs = array_column($this->getJson('/api/v2/catalogs/genres?per_page=200')->json('data'), 'slug', 'name');

        self::assertSame('!!', $slugs['!!']);
        self::assertSame('eclair-cut', $slugs['Éclair Cut']);
        self::assertSame('ドラマ', $slugs['ドラマ']);
    }

    public function test_the_script_index_lists_the_scripts_present_with_their_letters(): void
    {
        $response = $this->getJson('/api/v2/catalogs/genres/index')->assertOk();

        self::assertSame(['latin', 'ja', 'zh', 'other'], array_column($response->json('data'), 'script'));
        $response->assertJsonPath('data.0.label', 'Latin')->assertJsonPath('data.0.count', 3)->assertJsonPath('data.0.letters', [['letter' => 'A', 'count' => 1], ['letter' => 'C', 'count' => 1], ['letter' => 'D', 'count' => 1]]);
        $response->assertJsonPath('data.1.label', '日本語')->assertJsonPath('data.3.label', 'Khác');
    }

    public function test_the_script_index_honours_the_minimum_count_and_leaves_out_empty_scripts(): void
    {
        $response = $this->getJson('/api/v2/catalogs/genres/index?min_count=5')->assertOk();

        self::assertSame(['latin'], array_column($response->json('data'), 'script'));
        $response->assertJsonPath('data.0.count', 3);
    }

    public function test_one_entry_is_found_by_slug_or_by_exact_name_and_an_unknown_one_is_404(): void
    {
        $this->getJson('/api/v2/catalogs/genres/drama')->assertOk()->assertJsonPath('data.name', 'Drama')->assertJsonPath('data.cover_url', 'https://img/d.jpg');
        $this->getJson('/api/v2/catalogs/genres/' . rawurlencode('Éclair Cut'))->assertOk()->assertJsonPath('data.slug', 'eclair-cut');
        $this->getJson('/api/v2/catalogs/genres/nothing')->assertNotFound()->assertJsonPath('code', 'catalog_entry_not_found')->assertJsonPath('detail', 'No entry nothing in genres.');
    }

    public function test_performer_catalogues_point_at_performers_and_the_entries_are_cached(): void
    {
        $this->getJson('/api/v2/catalogs/cups')->assertOk()->assertJsonPath('data.0.kind', 'performers')->assertJsonPath('data.0.filter', 'cup');
        $queries = count($this->database->queries());

        $this->getJson('/api/v2/catalogs/cups/f')->assertOk()->assertJsonPath('data.name', 'F');
        self::assertSame($queries, count($this->database->queries()));
    }

    public function test_the_photo_collection_pages_performer_avatars_by_default(): void
    {
        $this->database
            ->answer('count(*) AS n FROM rm_performers', [['n' => 100]])
            ->answer('FROM rm_performers p WHERE', [['id' => '7', 'name_romaji' => 'Yui Hatano', 'photo_url' => 'https://img/p.jpg']]);

        $response = $this->getJson('/api/v2/photos?q=yui&per_page=500&page=2')->assertOk();

        $response->assertJsonPath('data.0', ['kind' => 'avatar', 'thumb_url' => 'https://img/p.jpg', 'full_url' => 'https://img/p.jpg', 'label' => 'Yui Hatano', 'performer_id' => 7]);
        $response->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 120, 'total' => 100, 'pages' => 1]);
        self::assertStringContainsString('OFFSET 120', $this->database->queries()[0]);
    }

    public function test_the_photo_collection_pages_movie_covers_labelled_by_the_best_title(): void
    {
        $this->database
            ->answer('count(*) AS n FROM rm_movies', [['n' => 3]])
            ->answer('FROM rm_movies m WHERE', [
                ['code' => 'AAA-001', 'cover_url' => 'https://img/c1.jpg', 'cover_thumb_url' => 'https://img/t1.jpg', 'title_en' => 'English', 'title_jp' => 'JP'],
                ['code' => 'BBB-002', 'cover_url' => 'https://img/c2.jpg', 'cover_thumb_url' => null, 'title_en' => '', 'title_jp' => 'JP'],
                ['code' => 'CCC-003', 'cover_url' => 'https://img/c3.jpg', 'cover_thumb_url' => null, 'title_en' => null, 'title_jp' => null],
            ]);

        $response = $this->getJson('/api/v2/photos?kind=cover')->assertOk();

        $response->assertJsonPath('data.0', ['kind' => 'cover', 'thumb_url' => 'https://img/t1.jpg', 'full_url' => 'https://img/c1.jpg', 'label' => 'English', 'movie_code' => 'AAA-001']);
        $response->assertJsonPath('data.1.thumb_url', 'https://img/c2.jpg')->assertJsonPath('data.1.label', 'JP')->assertJsonPath('data.2.label', 'CCC-003');
        $response->assertJsonPath('meta.pagination.per_page', 48);
    }
}
