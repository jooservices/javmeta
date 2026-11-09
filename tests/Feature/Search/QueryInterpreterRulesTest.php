<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\Movie;
use App\Models\Genre;
use App\Models\MovieGenre;
use App\Models\Performer;
use App\Services\Search\QueryInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(QueryInterpreter::class)]
final class QueryInterpreterRulesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['javmeta_ai.nl_enabled' => false]);
        Cache::flush();
        Performer::factory()->create(['name_romaji' => 'Aoi Tsukasa', 'blood_type' => 'A', 'cup' => 'D', 'location' => 'Tokyo']);
        Movie::factory()->create(['maker' => 'Moodyz', 'series' => 'Night Shift']);
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function questions(): iterable
    {
        yield 'code' => ['find ssis-001 please', ['code' => 'SSIS-001']];
        yield 'runtime over' => ['movies longer than 120 minutes', ['runtime_min' => 120]];
        yield 'runtime over vietnamese' => ['phim dài hơn 90 phút', ['runtime_min' => 90]];
        yield 'runtime under' => ['shorter than 60 min', ['runtime_max' => 60]];
        yield 'age range' => ['age 25 to 30', ['age_min' => 25, 'age_max' => 30]];
        yield 'age between' => ['aged between 22 and 28', ['age_min' => 22, 'age_max' => 28]];
        yield 'age single' => ['age is 24', ['age_min' => 24, 'age_max' => 24]];
        yield 'age under' => ['under 30 years old', ['age_max' => 30]];
        yield 'age over' => ['older than 40 years old', ['age_min' => 40]];
        yield 'age out of range is dropped' => ['age 150 to 200', []];
        yield 'age reversed range is dropped' => ['age 30 to 20', []];
        yield 'height range' => ['height 150 to 160', ['height_min' => 150, 'height_max' => 160]];
        yield 'height single' => ['height 165 cm', ['height_min' => 165, 'height_max' => 165]];
        yield 'blood type' => ['blood type A', ['blood_type' => 'A']];
        yield 'blood type japanese' => ['血液型A型', ['blood_type' => 'A']];
        yield 'blood type unknown value stays a keyword' => ['blood type O', []];
        yield 'cup' => ['cup D', ['cup' => 'D']];
        yield 'cup unknown stays a keyword' => ['cup H', []];
        yield 'location' => ['performers living in Tokyo', ['location' => 'Tokyo']];
        yield 'location japanese' => ['Tokyo在住', ['location' => 'Tokyo']];
        yield 'maker' => ['something from Moodyz', ['maker' => 'Moodyz']];
        yield 'series' => ['the Night Shift one', ['series' => 'Night Shift']];
        yield 'performer' => ['movies with Aoi Tsukasa', ['actress' => 'Aoi Tsukasa']];
        yield 'year range' => ['released 2018 to 2020', ['released_from' => '2018-01-01', 'released_to' => '2020-12-31']];
        yield 'after a year' => ['after 2019', ['released_from' => '2019-01-01']];
        yield 'after a year japanese' => ['2019年以降', ['released_from' => '2019-01-01']];
        yield 'before a year' => ['before 2015', ['released_to' => '2014-12-31']];
        yield 'before a year japanese' => ['2015年以前', ['released_to' => '2015-12-31']];
        yield 'before a year too early is ignored' => ['before 1950', []];
        yield 'in a year' => ['released in 2021', ['released_from' => '2021-01-01', 'released_to' => '2021-12-31']];
        yield 'in a year japanese' => ['2021年', ['released_from' => '2021-01-01', 'released_to' => '2021-12-31']];
    }

    /** @param array<string, mixed> $expected */
    #[DataProvider('questions')]
    public function test_the_rules_turn_a_question_into_filters(string $question, array $expected): void
    {
        $result = app(QueryInterpreter::class)->interpret($question);

        self::assertSame('rules', $result['by']);
        foreach ($expected as $field => $value) {
            self::assertSame($value, $result['filters'][$field] ?? null, $field);
        }
        self::assertSame(array_keys($expected), array_values(array_intersect(array_keys($result['filters']), array_keys($expected))), 'No expected filter is missing.');
        if ($expected === []) {
            self::assertSame([], $result['filters']);
        }
    }

    public function test_filler_words_are_removed_from_the_keywords(): void
    {
        $result = app(QueryInterpreter::class)->interpret('the movies by Moodyz about teachers');

        self::assertSame('Moodyz', $result['filters']['maker']);
        self::assertSame('about teachers', $result['keywords']);
    }

    public function test_japanese_filler_nouns_are_removed_from_the_keywords(): void
    {
        $result = app(QueryInterpreter::class)->interpret('先生 の映画');

        self::assertSame('先生', $result['keywords']);
    }

    public function test_rule_filters_ignore_a_rare_genre_name_inside_a_longer_phrase(): void
    {
        $genre = Genre::factory()->create(['label_normalized' => 'sweet', 'label_raw' => 'sweet']);
        $movie = Movie::factory()->create();
        MovieGenre::factory()->create(['movie_id' => $movie->id, 'genre_id' => $genre->id]);

        $result = app(QueryInterpreter::class)->interpret('sweet idol first time on camera');

        self::assertArrayNotHasKey('genre', $result['filters']);
        self::assertStringContainsString('sweet', $result['keywords']);
    }

    public function test_rule_filters_match_a_two_character_maker_only_when_it_has_enough_movies(): void
    {
        Movie::factory()->count(20)->create(['maker' => 'S1']);

        $result = app(QueryInterpreter::class)->interpret('latest movies from S1');

        self::assertSame('S1', $result['filters']['maker'] ?? null);
        self::assertArrayNotHasKey('genre', $result['filters']);
    }

    public function test_year_from_phrase_becomes_a_lower_release_bound(): void
    {
        $result = app(QueryInterpreter::class)->interpret('mature woman from 2022');

        self::assertSame('2022-01-01', $result['filters']['released_from'] ?? null);
    }

    public function test_rule_filters_match_a_catalog_performer_before_a_substring_genre(): void
    {
        Performer::factory()->create(['name_romaji' => 'Yua Mikami']);
        Genre::factory()->create(['label_normalized' => 'KAM', 'label_raw' => 'KAM']);
        Movie::factory()->count(20)->create(['maker' => 'KAM']);

        $result = app(QueryInterpreter::class)->interpret('movies with Yua Mikami');

        self::assertSame('Yua Mikami', $result['filters']['actress'] ?? null);
        self::assertArrayNotHasKey('genre', $result['filters']);
    }

    public function test_from_year_and_this_month_become_release_ranges(): void
    {
        $year = app(QueryInterpreter::class)->interpret('mature woman with big tits from 2022');
        $month = app(QueryInterpreter::class)->interpret('new releases this month');

        self::assertSame('2022-01-01', $year['filters']['released_from'] ?? null);
        self::assertSame('2022-12-31', $year['filters']['released_to'] ?? null);
        self::assertSame(now()->startOfMonth()->toDateString(), $month['filters']['released_from'] ?? null);
        self::assertSame(now()->endOfMonth()->toDateString(), $month['filters']['released_to'] ?? null);
    }

    public function test_telemetry_says_rules_were_used_when_the_model_is_off(): void
    {
        $telemetry = app(QueryInterpreter::class)->interpretWithTelemetry('movies with Aoi Tsukasa')['telemetry'];

        self::assertSame('rules', $telemetry['backend']);
        self::assertNull($telemetry['fallback_reason']);
        self::assertSame('not_attempted', $telemetry['ollama_chat_outcome']);
    }
}
