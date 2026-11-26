<?php

declare(strict_types=1);

namespace Tests\Unit\Api\V2;

use App\Services\Api\V2\Catalogue\CatalogueFilters;
use App\Services\Api\V2\Catalogue\SqlConditions;
use PHPUnit\Framework\TestCase;

final class CatalogueFiltersTest extends TestCase
{
    private CatalogueFilters $filters;

    protected function setUp(): void
    {
        $this->filters = new CatalogueFilters();
    }

    public function test_an_empty_query_only_hides_delisted_movies(): void
    {
        $c = $this->filters->movies([]);

        $this->assertSame('NOT m.delisted', $c->sql());
        $this->assertSame([], $c->bindings());
    }

    public function test_text_words_must_all_match_and_are_escaped(): void
    {
        $c = $this->filters->movies(['q' => 'Amateur 50%_x']);

        $this->assertStringContainsString('m.search_text LIKE ? AND m.search_text LIKE ?', $c->sql());
        $this->assertSame(['%amateur%', '%50\\%\\_x%'], array_slice($c->bindings(), 0, 2));
    }

    public function test_genres_any_all_and_exclusion(): void
    {
        $any = $this->filters->movies(['genre' => 'Drama, Creampie']);
        $all = $this->filters->movies(['genre' => 'Drama', 'genre_all' => '1', 'genre_not' => 'Gay']);

        $this->assertStringContainsString('m.genres && ?::text[]', $any->sql());
        $this->assertSame(['{"Drama","Creampie"}'], array_slice($any->bindings(), 0, 1));
        $this->assertStringContainsString('m.genres @> ?::text[]', $all->sql());
        $this->assertStringContainsString('NOT (m.genres && ?::text[])', $all->sql());
        $this->assertSame(['{"Drama"}', '{"Gay"}'], $all->bindings());
    }

    public function test_numeric_ranges_flags_and_enums(): void
    {
        $c = $this->filters->movies(['released_from' => '2020', 'runtime_max' => '120', 'score_min' => '3.5', 'tier_min' => '2', 'censored' => 'uncensored', 'has_magnet' => '0', 'has_performers' => '1', 'has_watch' => '1', 'crawled_days' => '7', 'source' => 'javdb,fc2', 'actress_id' => '3,x,4']);

        $sql = $c->sql();
        foreach (['m.year >= ?', 'm.runtime <= ?', 'm.score >= ?', 'm.tier >= ?', 'm.censored = 0', 'm.dl_count = 0', 'cardinality(m.performer_ids) > 0', 'm.hls_count > 0', "make_interval(days => ?::int)", 'm.sources && ?::text[]', 'm.performer_ids @> ?::int[]'] as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
        $this->assertContains('{"3","4"}', $c->bindings());
        $this->assertContains('{"javdb","fc2"}', $c->bindings());
    }

    public function test_omitting_a_filter_removes_only_that_filter(): void
    {
        $c = $this->filters->movies(['maker' => 's1', 'series' => 'x'], ['maker']);

        $this->assertStringNotContainsString('m.maker', $c->sql());
        $this->assertStringContainsString('m.series', $c->sql());
    }

    public function test_profile_filters_narrow_movies_by_cast(): void
    {
        $c = $this->filters->movies(['cup' => 'F', 'age_min' => '25']);

        $this->assertStringContainsString('EXISTS (SELECT 1 FROM rm_performers p WHERE p.id = ANY(m.performer_ids)', $c->sql());
        $this->assertStringContainsString('p.cup = ANY(?::text[])', $c->sql());
        $this->assertSame(['{"F"}', 25], $c->bindings());
    }

    public function test_performer_filters_cover_profile_text_and_ranges(): void
    {
        $c = $this->filters->performers(['q' => 'yuu', 'q_bio' => '1', 'cup' => 'F', 'blood_type' => 'A', 'location' => 'Tokyo', 'status' => 'retired', 'has_image' => '1', 'has_bio' => '0', 'has_profile' => '1', 'movies_min' => '5', 'bio_q' => 'dance', 'age_min' => '20', 'age_max' => '30', 'debut_from' => '2010', 'born_to' => '2000', 'height_min' => '150', 'hip_max' => '95']);

        $sql = $c->sql();
        foreach (['p.name_romaji IS NOT NULL', "lower(coalesce(p.bio_text, ''))", 'p.cup = ANY(?::text[])', 'lower(p.location) LIKE ?', 'p.retired_date IS NOT NULL', 'p.photo_url IS NOT NULL', 'p.bio_text IS NULL', 'p.movie_count >= ?', 'extract(year from p.debut_date) >= ?', 'extract(year from p.birth_date) <= ?', 'p.height_cm >= ?', 'p.hip <= ?'] as $fragment) {
            $this->assertStringContainsString($fragment, $sql);
        }
        $this->assertContains(31, $c->bindings());
    }

    public function test_active_status_and_missing_profile(): void
    {
        $c = $this->filters->performers(['status' => 'active', 'has_profile' => '0', 'has_image' => '0']);

        $this->assertStringContainsString('p.retired_date IS NULL', $c->sql());
        $this->assertStringContainsString('NOT (p.birth_date IS NOT NULL', $c->sql());
        $this->assertStringContainsString('p.photo_url IS NULL', $c->sql());
    }

    public function test_movie_side_filters_select_performers_by_their_movies(): void
    {
        $c = $this->filters->performers(['genre' => 'Drama', 'maker' => 'S1', 'age_min' => '25']);

        $this->assertStringContainsString('p.id IN (SELECT unnest(m.performer_ids) FROM rm_movies m WHERE', $c->sql());
        $this->assertSame([25, '{"Drama"}', '%s1%'], $c->bindings());
    }

    public function test_array_literals_escape_quotes_and_backslashes(): void
    {
        $this->assertSame('{"a\\"b","c\\\\d"}', SqlConditions::pgArray(['a"b', 'c\\d']));
        $this->assertSame('{"1","2"}', SqlConditions::pgArray([1, 2]));
        $this->assertTrue((new SqlConditions())->isEmpty());
        $this->assertSame('TRUE', (new SqlConditions())->sql());
    }
}
