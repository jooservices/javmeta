<?php

declare(strict_types=1);

namespace Tests\Integration\Api\V2;

use App\Models\User;

/** Unified search, suggestions, interpretations, photo search and catalogue matches on real PostgreSQL. */
final class DiscoverySearchTest extends DiscoveryTestCase
{
    public function test_search_finds_movies_performers_and_photos_and_hides_delisted_movies(): void
    {
        $response = $this->getJson('/api/v2/search?q=alpha')->assertOk();
        $response->assertJsonPath('data.movies.total', 1)->assertJsonPath('data.movies.rows.0.code', 'AAA-001')->assertJsonPath('meta.query', 'alpha');
        $response->assertJsonPath('data.performers.total', 0)->assertJsonPath('data.photos.total', 0)->assertJsonPath('meta.did_you_mean', null)->assertJsonPath('meta.looks_like_code', false);

        $people = $this->getJson('/api/v2/search?q=aoi')->assertOk();
        $people->assertJsonPath('data.performers.rows.0.name', 'Aoi Test')->assertJsonPath('data.photos.rows.0.kind', 'avatar')->assertJsonPath('data.photos.rows.0.performer_id', 1);
        $this->getJson('/api/v2/search?q=delta')->assertOk()->assertJsonPath('data.movies.total', 0);
    }

    public function test_an_exact_code_is_ranked_first_and_flagged_as_a_code(): void
    {
        $response = $this->getJson('/api/v2/search?q=bbb002&types=movie')->assertOk();

        $response->assertJsonPath('data.movies.rows.0.code', 'BBB-002')->assertJsonPath('data.performers', null)->assertJsonPath('data.photos', null)->assertJsonPath('meta.looks_like_code', true);
    }

    public function test_the_types_and_paging_narrow_the_answer(): void
    {
        $this->getJson('/api/v2/search?q=a&types=movie&per_page=1&page=2')->assertOk()->assertJsonCount(1, 'data.movies.rows');
        $this->getJson('/api/v2/search?q=a&types=performer')->assertOk()->assertJsonPath('data.movies', null);
    }

    public function test_a_search_with_no_result_offers_the_closest_name(): void
    {
        $this->getJson('/api/v2/search?q=aoy%20tesst')->assertOk()->assertJsonPath('meta.did_you_mean', 'Aoi Test');
    }

    public function test_a_missing_text_is_a_validation_error(): void
    {
        $this->getJson('/api/v2/search')->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('errors.0.field', 'q');
    }

    public function test_suggestions_list_movies_performers_makers_series_and_genres(): void
    {
        $this->getJson('/api/v2/suggestions?q=one')->assertOk()->assertJsonPath('data.makers.0.name', 'Maker One')->assertJsonPath('data.makers.0.slug', 'maker-one')->assertJsonPath('data.series.0.name', 'Series One');
        $this->getJson('/api/v2/suggestions?q=dra')->assertOk()->assertJsonPath('data.genres.0.name', 'Drama')->assertJsonPath('data.genres.0.count', 2);
        $this->getJson('/api/v2/suggestions?q=aoi&limit=99')->assertOk()->assertJsonPath('data.performers.0.slug', '1-aoi-test')->assertJsonPath('data.performers.0.movie_count', 3);
        $this->getJson('/api/v2/suggestions?q=alpha')->assertOk()->assertJsonPath('data.movies.0.code', 'AAA-001')->assertJsonPath('data.movies.0.title', 'First alpha');
    }

    public function test_a_too_short_suggestion_text_answers_empty_lists(): void
    {
        $this->getJson('/api/v2/suggestions?q=a')->assertOk()->assertJsonPath('data.movies', [])->assertJsonPath('data.genres', []);
        $this->getJson('/api/v2/suggestions')->assertOk()->assertJsonPath('data.performers', []);
    }

    public function test_the_question_reader_finds_year_runtime_genre_maker_series_and_a_performer(): void
    {
        $this->seedCatalogueWords();

        $reading = $this->postJson('/api/v2/search/interpretations', ['q' => 'Aoi Test 2025 under 120 min slender genre acme studio long saga'])->assertOk();

        $reading->assertJsonPath('data.filters.released_from', '2025')->assertJsonPath('data.filters.runtime_max', '120')->assertJsonPath('data.filters.genre', 'Slender Genre');
        $reading->assertJsonPath('data.filters.maker', 'Acme Studio')->assertJsonPath('data.filters.series', 'Long Saga')->assertJsonPath('data.filters.actress_id', '1');
        self::assertSame(['Năm', 'Thời lượng', 'Thể loại', 'Hãng', 'Series', 'Diễn viên'], array_column($reading->json('data.conditions'), 'k'));
    }

    public function test_the_question_reader_reads_over_and_a_condition_can_be_switched_off(): void
    {
        $this->seedCatalogueWords();

        $over = $this->postJson('/api/v2/search/interpretations', ['q' => 'movies over 90 minutes'])->assertOk();
        $over->assertJsonPath('data.filters.runtime_min', '90')->assertJsonPath('data.conditions.0.v', '≥ 90 phút');

        $off = $this->postJson('/api/v2/search/interpretations', ['q' => 'over 90 something', 'ignore' => ['Thời lượng:≥ 90 phút']])->assertOk();
        $off->assertJsonMissingPath('data.filters.runtime_min')->assertJsonPath('data.ignored.0.id', 'Thời lượng:≥ 90 phút')->assertJsonPath('data.residual', 'something');
    }

    public function test_a_bare_genre_word_becomes_a_topic_unless_ignored(): void
    {
        $this->seedCatalogueWords();

        $this->postJson('/api/v2/search/interpretations', ['q' => 'slender'])->assertOk()->assertJsonPath('data.filters.genre', 'Slender Genre')->assertJsonPath('data.residual', '');
        $this->postJson('/api/v2/search/interpretations', ['q' => 'slender', 'ignore' => ['Thể loại:Slender Genre']])->assertOk()->assertJsonMissingPath('data.filters.genre')->assertJsonPath('data.residual', 'slender');
    }

    public function test_a_text_that_means_nothing_gives_empty_filters_as_an_object(): void
    {
        $this->postJson('/api/v2/search/interpretations', ['q' => 'x'])->assertOk()->assertJsonPath('data.filters', [])->assertJsonPath('data.conditions', []);
        self::assertStringContainsString('"filters":{}', (string) $this->postJson('/api/v2/search/interpretations', ['q' => 'x'])->getContent());
    }

    public function test_the_question_reader_reads_performer_age_blood_cup_height_and_location(): void
    {
        $reading = $this->postJson('/api/v2/search/interpretations', ['q' => 'dien vien nu 20-25 tuoi nhom mau ab cup d cao tren 160 o tokyo', 'target' => 'performers'])->assertOk();

        $reading->assertJsonPath('data.filters.age_min', '20')->assertJsonPath('data.filters.age_max', '25')->assertJsonPath('data.filters.blood_type', 'AB')->assertJsonPath('data.filters.cup', 'D');
        $reading->assertJsonPath('data.filters.height_min', '160')->assertJsonPath('data.filters.location', 'Tokyo')->assertJsonPath('data.residual', '');
        self::assertSame(['Tuổi', 'Nhóm máu', 'Cup', 'Chiều cao', 'Nơi ở'], array_column($reading->json('data.conditions'), 'k'));
    }

    public function test_the_performer_reader_knows_each_age_and_height_form_a_location_and_what_to_ignore(): void
    {
        $one = fn(string $text, array $ignore = []): array => $this->postJson('/api/v2/search/interpretations', ['q' => $text, 'target' => 'performers', 'ignore' => $ignore])->assertOk()->json('data');

        self::assertSame(['age_max' => '30'], $one('duoi 30 tuoi')['filters']);
        self::assertSame(['age_min' => '40'], $one('over 40 yo')['filters']);
        self::assertSame(['age_min' => '25', 'age_max' => '25'], $one('25 tuoi')['filters']);
        self::assertSame(['age_min' => '22', 'age_max' => '22'], $one('age 22')['filters']);
        self::assertSame(['age_min' => '30', 'age_max' => '40'], $one('30 den 40 yo')['filters']);
        self::assertSame([], $one('90 tuoi')['filters']);
        self::assertSame(['blood_type' => 'O'], $one('blood type o')['filters']);
        self::assertSame(['cup' => 'F'], $one('f cup')['filters']);
        self::assertSame(['height_max' => '150'], $one('height duoi 150 cm')['filters']);
        self::assertSame(['height_min' => '155', 'height_max' => '155'], $one('cao 155')['filters']);
        self::assertSame(['location' => 'Osaka'], $one('osaka')['filters']);

        $off = $one('cup d osaka', ['Cup:D', 'Nơi ở:Osaka']);
        self::assertSame([], $off['filters']);
        self::assertSame('osaka', $off['residual']);
    }

    public function test_the_interpretation_needs_a_text_and_a_known_target_and_no_csrf_for_a_signed_in_user(): void
    {
        $this->postJson('/api/v2/search/interpretations', [])->assertStatus(422)->assertJsonPath('errors.0.field', 'q');
        $this->postJson('/api/v2/search/interpretations', ['q' => 'a', 'target' => 'photos'])->assertStatus(422)->assertJsonPath('errors.0.field', 'target');

        $this->actingAs(User::factory()->create(), 'web')->postJson('/api/v2/search/interpretations', ['q' => 'hello there'])->assertOk();
    }

    public function test_photo_search_gives_covers_and_the_cast_of_the_matching_movies(): void
    {
        $response = $this->getJson('/api/v2/search/photos?maker=Maker%20One')->assertOk();

        $response->assertJsonPath('data.total', 2)->assertJsonPath('data.covers.0.movie_code', 'AAA-001')->assertJsonPath('data.covers.0.to', '/movies/AAA-001')->assertJsonPath('data.people.0.performer_id', 1);
        $this->getJson('/api/v2/search/photos?maker=Maker%20One&page=2')->assertOk()->assertJsonPath('data.people', [])->assertJsonPath('meta.page', 2);
        $this->getJson('/api/v2/search/photos?cup=D')->assertOk()->assertJsonPath('data.people', []);
    }

    public function test_catalogue_search_matches_names_of_genres_makers_series_and_labels(): void
    {
        $response = $this->getJson('/api/v2/search/catalog?q=one')->assertOk();

        self::assertEqualsCanonicalizing(['makers:Maker One', 'series:Series One', 'labels:Label One'], array_map(static fn(array $r): string => $r['kind'] . ':' . $r['name'], $response->json('data')));
        $this->getJson('/api/v2/search/catalog?q=drama%20comedy')->assertOk()->assertJsonPath('data.0.kind', 'genres');
        $this->getJson('/api/v2/search/catalog?q=x')->assertOk()->assertJsonPath('data', []);
        $this->getJson('/api/v2/search/catalog')->assertOk()->assertJsonPath('data', []);
    }
}
