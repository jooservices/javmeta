<?php

declare(strict_types=1);

namespace Tests\Unit\Api\V2;

use App\Repositories\Api\V2\InterpretationRepository;
use App\Services\Api\V2\Discovery\InterpretationService;
use App\Services\Api\V2\Discovery\ReadCache;
use Tests\Support\Api\V2\FakesPostgresRows;
use Tests\TestCase;

final class InterpretationServiceTest extends TestCase
{
    use FakesPostgresRows;

    private InterpretationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeRows([
            'unnest(m.genres) g' => [
                ['name' => 'Big Tits', 'n' => 500],
                ['name' => 'Anal', 'n' => 900],
                ['name' => 'Creampie', 'n' => 800],
                ['name' => 'Solo Work', 'n' => 700],
                ['name' => 'Rare Genre', 'n' => 50],
                ['name' => 'Sex', 'n' => 999],
            ],
            'FROM rm_movies WHERE maker IS NOT NULL' => [
                ['name' => 'Moodyz', 'n' => 300],
                ['name' => 'Moodyz Diva', 'n' => 40],
                ['name' => 'S1', 'n' => 500],
                ['name' => 'Tiny Maker', 'n' => 5],
            ],
            'FROM rm_movies WHERE series IS NOT NULL' => [
                ['name' => 'Night Shift', 'n' => 25],
                ['name' => 'Few', 'n' => 99],
                ['name' => 'Seldom Seen', 'n' => 3],
            ],
            'FROM rm_performers WHERE location IS NOT NULL' => [
                ['name' => 'Tokyo', 'n' => 400],
                ['name' => 'Ōsaka', 'n' => 100],
                ['name' => 'X', 'n' => 1],
            ],
            'FROM rm_performers WHERE lower(name_romaji)' => static fn(array $bindings): array => str_contains((string) $bindings[0], '"yua mikami"') ? [['id' => 7, 'name' => 'Yua Mikami']] : [],
        ]);

        $this->service = new InterpretationService(new InterpretationRepository(), new ReadCache());
    }

    public function test_an_empty_question_reads_nothing_and_asks_the_database_for_nothing_about_people(): void
    {
        $reading = $this->service->movies('', []);

        $this->assertSame(['filters' => [], 'conditions' => [], 'ignored' => [], 'residual' => ''], $reading);
        $this->assertSame([], $this->sqlContaining('name_romaji'));
    }

    public function test_a_blank_question_with_only_spaces_is_empty_too(): void
    {
        $this->assertSame([], $this->service->movies('    ', [])['filters']);
        $this->assertSame(['filters' => [], 'conditions' => [], 'ignored' => [], 'residual' => ''], $this->service->performers('   ', []));
    }

    public function test_a_year_becomes_a_release_range_condition_and_is_taken_out_of_the_residual(): void
    {
        $reading = $this->service->movies('Phim năm 2023', []);

        $this->assertSame(['released_from' => '2023', 'released_to' => '2023'], $reading['filters']);
        $this->assertSame([['k' => 'Năm', 'v' => '2023', 'id' => 'Năm:2023', 'keys' => ['released_from', 'released_to']]], $reading['conditions']);
        $this->assertSame('', $reading['residual']);
    }

    public function test_only_years_of_the_nineteen_hundreds_and_two_thousands_count(): void
    {
        $this->assertSame([], $this->service->movies('1899 and 2100', [])['conditions']);
        $this->assertSame('1999', $this->service->movies('year 1999', [])['filters']['released_from']);
    }

    public function test_runtime_upper_bounds_in_vietnamese_and_english(): void
    {
        foreach (['phim dưới 90 phút', 'under 90 min', '< 90', 'less than 90', 'ngắn hơn 90'] as $phrase) {
            $reading = $this->service->movies($phrase, []);

            $this->assertSame('90', $reading['filters']['runtime_max'], $phrase);
            $this->assertSame('≤ 90 phút', $reading['conditions'][0]['v'], $phrase);
        }
    }

    public function test_runtime_lower_bounds_in_vietnamese_and_english(): void
    {
        foreach (['trên 120 phút', 'over 120', '> 120 min', 'more than 120', 'dài hơn 120'] as $phrase) {
            $reading = $this->service->movies($phrase, []);

            $this->assertSame('120', $reading['filters']['runtime_min'], $phrase);
            $this->assertSame('≥ 120 phút', $reading['conditions'][0]['v'], $phrase);
        }
    }

    public function test_a_runtime_range_sets_both_bounds_and_the_numbers_never_leak_into_the_search_text(): void
    {
        $reading = $this->service->movies('trên 60 phút dưới 180 phút', []);

        $this->assertSame(['runtime_max' => '180', 'runtime_min' => '60'], $reading['filters']);
        $this->assertSame('', $reading['residual']);
    }

    public function test_a_single_digit_runtime_is_not_read(): void
    {
        $this->assertSame([], $this->service->movies('under 9', [])['conditions']);
    }

    public function test_genre_words_set_the_genre_filter_with_the_matching_names(): void
    {
        $reading = $this->service->movies('big tits anal', []);

        $this->assertSame(['genre' => 'Big Tits,Anal'], $reading['filters']);
        $this->assertSame('Thể loại', $reading['conditions'][0]['k']);
        $this->assertSame('Big Tits, Anal', $reading['conditions'][0]['v']);
        $this->assertSame(['genre'], $reading['conditions'][0]['keys']);
        $this->assertSame('', $reading['residual']);
    }

    public function test_at_most_three_genres_are_taken_and_rare_or_short_genre_names_never_match(): void
    {
        $reading = $this->service->movies('big tits anal creampie solo work rare genre sex', []);

        $this->assertSame('Big Tits,Anal,Creampie', $reading['filters']['genre']);
        $this->assertStringNotContainsString('Rare Genre', $reading['filters']['genre']);
        $this->assertStringNotContainsString('Sex', $reading['filters']['genre']);
    }

    public function test_a_genre_word_typed_with_other_text_keeps_the_rest_as_the_search_text(): void
    {
        $reading = $this->service->movies('Big Tits sensational', []);

        $this->assertSame(['genre' => 'Big Tits', 'q' => 'sensational'], $reading['filters']);
        $this->assertSame('sensational', $reading['residual']);
    }

    public function test_the_longest_maker_name_wins(): void
    {
        $reading = $this->service->movies('moodyz diva best', []);

        $this->assertSame(['maker' => 'Moodyz Diva', 'q' => 'best'], $reading['filters']);
        $this->assertSame('Hãng', $reading['conditions'][0]['k']);
    }

    public function test_makers_with_few_movies_or_two_letter_names_are_not_matched(): void
    {
        $this->assertSame([], $this->service->movies('s1 tiny maker', [])['conditions']);
    }

    public function test_a_series_is_matched_when_it_is_long_and_common_enough(): void
    {
        $reading = $this->service->movies('night shift', []);

        $this->assertSame(['series' => 'Night Shift'], $reading['filters']);
        $this->assertSame('Series', $reading['conditions'][0]['k']);
        $this->assertSame([], $this->service->movies('few seldom seen', [])['conditions']);
    }

    public function test_a_known_performer_is_found_by_her_full_name_in_any_case(): void
    {
        $reading = $this->service->movies('Phim của YUA MIKAMI', []);

        $this->assertSame(['actress_id' => '7'], $reading['filters']);
        $this->assertSame('Diễn viên', $reading['conditions'][0]['k']);
        $this->assertSame('Yua Mikami', $reading['conditions'][0]['v']);
        $this->assertSame('', $reading['residual']);

        $bindings = $this->sqlContaining('name_romaji')[0]['bindings'][0];
        $this->assertStringContainsString('"yua mikami"', $bindings);
    }

    public function test_performer_candidates_are_windows_of_up_to_four_words_longer_than_four_characters(): void
    {
        $this->service->movies('a bb ccc dd eeeee ffffff', []);

        $array = (string) $this->sqlContaining('name_romaji')[0]['bindings'][0];

        $this->assertStringContainsString('"ccc dd"', $array);
        $this->assertStringContainsString('"eeeee"', $array);
        $this->assertStringContainsString('"a bb ccc dd"', $array);
        $this->assertStringNotContainsString('"bb"', $array);
        $this->assertStringNotContainsString('"a bb ccc dd eeeee"', $array);
    }

    public function test_an_unknown_name_is_just_search_text(): void
    {
        $reading = $this->service->movies('nobody famous', []);

        $this->assertSame(['q' => 'nobody famous'], $reading['filters']);
        $this->assertSame([], $reading['conditions']);
    }

    public function test_ignored_conditions_are_listed_but_do_not_filter_and_are_not_searched_for_as_text(): void
    {
        $reading = $this->service->movies('moodyz 2021', ['Năm:2021']);

        $this->assertSame(['maker' => 'Moodyz'], $reading['filters']);
        $this->assertSame(['Hãng:Moodyz'], array_column($reading['conditions'], 'id'));
        $this->assertSame(['Năm:2021'], array_column($reading['ignored'], 'id'));
        $this->assertSame(['released_from', 'released_to'], $reading['ignored'][0]['keys']);
        $this->assertSame('', $reading['residual']);
    }

    public function test_a_bare_genre_word_is_a_topic_and_ends_the_search_text(): void
    {
        $reading = $this->service->movies('tits', []);

        $this->assertSame(['genre' => 'Big Tits'], $reading['filters']);
        $this->assertSame('', $reading['residual']);
        $this->assertSame('Thể loại:Big Tits', $reading['conditions'][0]['id']);
    }

    public function test_a_bare_genre_word_that_the_client_switched_off_stays_off(): void
    {
        $reading = $this->service->movies('tits', ['Thể loại:Big Tits']);

        $this->assertSame(['q' => 'tits'], $reading['filters']);
        $this->assertSame([], $reading['conditions']);
    }

    public function test_short_words_never_make_a_bare_genre(): void
    {
        $this->assertSame([], $this->service->movies('big', [])['conditions']);
    }

    public function test_stop_words_and_two_letter_words_are_dropped_from_the_residual(): void
    {
        $this->assertSame('', $this->service->movies('phim the and for movie xx', [])['residual']);
        $this->assertSame('gorgeous', $this->service->movies('phim gorgeous', [])['filters']['q']);
    }

    public function test_the_word_lists_are_read_once_and_then_served_from_the_cache(): void
    {
        $this->service->movies('anal', []);
        $this->service->movies('2020', []);
        $this->service->performers('tokyo', []);
        $this->service->performers('osaka', []);

        $this->assertCount(1, $this->sqlContaining('unnest(m.genres) g'));
        $this->assertCount(1, $this->sqlContaining('maker IS NOT NULL'));
        $this->assertCount(1, $this->sqlContaining('location IS NOT NULL'));
    }

    public function test_performer_age_ranges_in_both_orders_and_with_several_separators(): void
    {
        foreach (['tuổi 20-30', '20 - 30 tuổi', 'age 20 to 30', '20 đến 30 age', 'tuoi 20 den 30'] as $phrase) {
            $reading = $this->service->performers($phrase, []);

            $this->assertSame(['age_min' => '20', 'age_max' => '30'], $reading['filters'], $phrase);
            $this->assertSame('Tuổi:20-30', $reading['conditions'][0]['id'], $phrase);
        }
    }

    public function test_a_reversed_or_underage_range_is_not_an_age_range_but_a_single_age_may_still_be_read(): void
    {
        $reversed = $this->service->performers('tuổi 30-20', []);
        $this->assertSame(['age_min' => '30', 'age_max' => '30'], $reversed['filters']);

        $this->assertSame([], $this->service->performers('tuoi 10-15', [])['filters']);
        $this->assertSame(['age_min' => '18', 'age_max' => '18'], $this->service->performers('tuoi 18-90', [])['filters']);
    }

    public function test_performer_age_limits(): void
    {
        $this->assertSame(['age_max' => '30'], $this->service->performers('dưới 30 tuổi', [])['filters']);
        $this->assertSame(['age_max' => '30'], $this->service->performers('under 30 yo', [])['filters']);
        $this->assertSame(['age_min' => '40'], $this->service->performers('trên 40 tuổi', [])['filters']);
        $this->assertSame(['age_min' => '40'], $this->service->performers('over 40 years', [])['filters']);
        $this->assertSame('Tuổi:≤30', $this->service->performers('<30 age', [])['conditions'][0]['id']);
        $this->assertSame('Tuổi:≥40', $this->service->performers('>40 tuoi', [])['conditions'][0]['id']);
    }

    public function test_the_vietnamese_d_with_a_stroke_is_read_as_a_plain_d(): void
    {
        $this->assertSame(['age_min' => '20', 'age_max' => '30'], $this->service->performers('20 đến 30 tuổi', [])['filters']);
        $this->assertSame(['height_max' => '150'], $this->service->performers('Cao Dưới 150', [])['filters']);
    }

    public function test_a_single_performer_age_is_a_fixed_age_between_18_and_80(): void
    {
        $this->assertSame(['age_min' => '25', 'age_max' => '25'], $this->service->performers('25 tuổi', [])['filters']);
        $this->assertSame(['age_min' => '25', 'age_max' => '25'], $this->service->performers('age 25', [])['filters']);
        $this->assertSame([], $this->service->performers('17 tuoi', [])['filters']);
        $this->assertSame([], $this->service->performers('81 tuoi', [])['filters']);
    }

    public function test_blood_type_cup_and_height(): void
    {
        $this->assertSame(['blood_type' => 'AB'], $this->service->performers('nhóm máu ab', [])['filters']);
        $this->assertSame(['blood_type' => 'O'], $this->service->performers('blood type o', [])['filters']);
        $this->assertSame(['blood_type' => 'A'], $this->service->performers('blood a', [])['filters']);
        $this->assertSame(['cup' => 'D'], $this->service->performers('cup d', [])['filters']);
        $this->assertSame(['cup' => 'F'], $this->service->performers('f cup', [])['filters']);
        $this->assertSame([], $this->service->performers('cup z', [])['filters']);
    }

    public function test_height_exact_above_and_below(): void
    {
        $exact = $this->service->performers('cao 165cm', []);
        $this->assertSame(['height_min' => '165', 'height_max' => '165'], $exact['filters']);
        $this->assertSame('165 cm', $exact['conditions'][0]['v']);
        $this->assertSame(['height_min' => '170'], $this->service->performers('cao trên 170', [])['filters']);
        $this->assertSame(['height_max' => '150'], $this->service->performers('height under 150 cm', [])['filters']);
        $this->assertSame('≥ 170 cm', $this->service->performers('cao > 170', [])['conditions'][0]['v']);
        $this->assertSame('≤ 150 cm', $this->service->performers('cao duoi 150', [])['conditions'][0]['v']);
    }

    public function test_a_location_is_matched_without_diacritics_and_taken_out_of_the_text(): void
    {
        $reading = $this->service->performers('diễn viên nữ ở Osaka', []);

        $this->assertSame(['location' => 'Ōsaka'], $reading['filters']);
        $this->assertSame('', $reading['residual']);
    }

    public function test_a_location_the_client_switched_off_is_dropped_and_left_in_the_text(): void
    {
        $reading = $this->service->performers('tokyo', ['Nơi ở:Tokyo']);

        $this->assertSame([], $reading['filters']);
        $this->assertSame('tokyo', $reading['residual']);
    }

    public function test_a_whole_performer_question_in_vietnamese(): void
    {
        $reading = $this->service->performers('Diễn viên nữ 25 tuổi nhóm máu A cup D cao 165 ở Tokyo tên Mikami', []);

        $this->assertSame([
            'age_min' => '25',
            'age_max' => '25',
            'blood_type' => 'A',
            'cup' => 'D',
            'height_min' => '165',
            'height_max' => '165',
            'location' => 'Tokyo',
        ], $reading['filters']);
        $this->assertSame(['Tuổi:25', 'Nhóm máu:A', 'Cup:D', 'Chiều cao:165', 'Nơi ở:Tokyo'], array_column($reading['conditions'], 'id'));
        $this->assertSame('ten mikami', $reading['residual']);
        $this->assertSame([], $reading['ignored']);
    }

    public function test_ignored_performer_conditions_leave_the_filters_but_keep_the_answer_shape(): void
    {
        $reading = $this->service->performers('25 tuổi cup d', ['Tuổi:25']);

        $this->assertSame(['cup' => 'D'], $reading['filters']);
        $this->assertSame(['Cup:D'], array_column($reading['conditions'], 'id'));
    }

    public function test_performer_text_that_is_not_a_condition_is_returned_as_residual_but_not_as_a_filter(): void
    {
        $reading = $this->service->performers('mikami yua', []);

        $this->assertSame([], $reading['filters']);
        $this->assertSame('mikami yua', $reading['residual']);
    }
}
