<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Normalize;

use App\Data\Crawl\PerformerDraft;
use App\Services\Normalize\PerformerAttributeNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PerformerAttributeNormalizer::class)]
final class PerformerAttributeNormalizerTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function rules(): array
    {
        return [
            'blood_types' => ['A', 'B', 'O', 'AB'],
            'cups' => ['A', 'B', 'C', 'D', 'E', 'F'],
            'junk_tokens' => [
                'blood_type' => ['-', 'n/a', 'trans'],
                'blood_rh' => ['-'],
                'cup' => ['-', '不明'],
                'height_cm' => ['0'],
                'birth_date' => ['unknown'],
                'location' => ['-', 'unknown'],
            ],
            'field_ranges' => ['debut_date' => ['min' => '1990-01-01', 'max' => '2100-01-01']],
            'location_aliases' => ['tokyo' => 'Tokyo', '東京' => 'Tokyo', 'osaka' => 'Osaka', '' => 'ignored', 'bad' => ''],
        ];
    }

    private function normalizer(): PerformerAttributeNormalizer
    {
        return new PerformerAttributeNormalizer(self::rules());
    }

    /** @return iterable<string, array{mixed, ?string, ?string}> */
    public static function bloodTypes(): iterable
    {
        yield 'plain' => ['A', 'A', null];
        yield 'lower case' => ['ab', 'AB', null];
        yield 'japanese suffix' => ['A型', 'A', null];
        yield 'japanese label' => ['血液型 O', 'O', null];
        yield 'type prefix' => ['Type B', 'B', null];
        yield 'blood type suffix' => ['B blood type', 'B', null];
        yield 'full width' => ['ＡＢ', 'AB', null];
        yield 'with rh plus' => ['A+', 'A', '+'];
        yield 'with rh minus' => ['O Rh-', 'O', '-'];
        yield 'empty' => ['', null, null];
        yield 'null' => [null, null, null];
        yield 'junk token' => ['trans', null, null];
        yield 'only a dash' => ['-', null, null];
        yield 'unknown letter' => ['Z', null, null];
        yield 'not a string' => [5, null, null];
    }

    #[DataProvider('bloodTypes')]
    public function test_blood_type_is_canonical_or_null(mixed $raw, ?string $value, ?string $rh): void
    {
        self::assertSame(['value' => $value, 'rh' => $rh], $this->normalizer()->bloodType($raw, 'src', false));
    }

    public function test_a_blood_type_not_in_the_configured_list_is_rejected(): void
    {
        $normalizer = new PerformerAttributeNormalizer(['blood_types' => ['A']] + self::rules());

        self::assertSame(['value' => null, 'rh' => null], $normalizer->bloodType('B', 'src', false));
        self::assertSame('A', $normalizer->bloodType('A', 'src', false)['value']);
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function rhValues(): iterable
    {
        yield 'plus' => ['+', '+'];
        yield 'positive' => ['positive', '+'];
        yield 'pos' => ['POS', '+'];
        yield 'rh prefix' => ['Rh+', '+'];
        yield 'minus' => ['-', null];
        yield 'negative' => ['negative', '-'];
        yield 'neg' => ['neg', '-'];
        yield 'int is not valid' => [7, null];
        yield 'array' => [['x'], null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
        yield 'garbage' => ['maybe', null];
    }

    #[DataProvider('rhValues')]
    public function test_rh_factor_is_plus_minus_or_null(mixed $raw, ?string $expected): void
    {
        // '-' is configured as junk for blood_rh in these rules, so a bare dash is a rejection, not a minus.
        self::assertSame($expected, $this->normalizer()->bloodRh($raw, 'src', false));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function cups(): iterable
    {
        yield 'letter' => ['D', 'D'];
        yield 'lower' => ['d', 'D'];
        yield 'with katakana word' => ['Eカップ', 'E'];
        yield 'spaced' => [' F ', 'F'];
        yield 'outside configured cups' => ['K', null];
        yield 'two letters' => ['DD', null];
        yield 'junk' => ['不明', null];
        yield 'not a string' => [3, null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('cups')]
    public function test_cup_is_a_configured_letter_or_null(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer()->cup($raw, 'src', false));
    }

    /** @return iterable<string, array{string, mixed, ?int}> */
    public static function measurements(): iterable
    {
        yield 'int in range' => ['height_cm', 160, 160];
        yield 'string with cm' => ['height_cm', '158cm', 158];
        yield 'string spaced' => ['height_cm', '158 CM', 158];
        yield 'full width digits' => ['height_cm', '１５８', 158];
        yield 'below range' => ['height_cm', 99, null];
        yield 'above range' => ['height_cm', 251, null];
        yield 'junk int' => ['height_cm', 0, null];
        yield 'junk string' => ['height_cm', '0', null];
        yield 'text' => ['height_cm', 'tall', null];
        yield 'float is not accepted' => ['height_cm', 160.5, null];
        yield 'bust' => ['bust', 88, 88];
        yield 'waist low edge' => ['waist', 30, 30];
        yield 'hip high edge' => ['hip', 250, 250];
        yield 'empty' => ['bust', '', null];
        yield 'null' => ['bust', null, null];
    }

    #[DataProvider('measurements')]
    public function test_measurements_stay_inside_their_ranges(string $field, mixed $raw, ?int $expected): void
    {
        self::assertSame($expected, $this->normalizer()->measurement($field, $raw, 'src', false));
    }

    /** @return iterable<string, array{string, mixed, ?string}> */
    public static function dates(): iterable
    {
        yield 'iso' => ['birth_date', '1995-04-05', '1995-04-05'];
        yield 'iso with time' => ['birth_date', '1995-04-05 10:11:12', '1995-04-05'];
        yield 'iso with zone' => ['birth_date', '1995-04-05T10:11:12Z', '1995-04-05'];
        yield 'slashes' => ['birth_date', '1995/4/5', '1995-04-05'];
        yield 'japanese' => ['birth_date', '1995年4月5日', '1995-04-05'];
        yield 'compact' => ['birth_date', '19950405', '1995-04-05'];
        yield 'datetime object' => ['birth_date', new DateTimeImmutable('1995-04-05'), '1995-04-05'];
        yield 'impossible iso date' => ['birth_date', '1995-02-30', null];
        yield 'impossible compact date' => ['birth_date', '19950231', null];
        yield 'before minimum' => ['birth_date', '1850-01-01', null];
        yield 'in the future' => ['birth_date', '2999-01-01', null];
        yield 'junk' => ['birth_date', 'unknown', null];
        yield 'text' => ['birth_date', 'yesterday', null];
        yield 'number' => ['birth_date', 19950405, null];
        yield 'custom range accepts' => ['debut_date', '2015-06-01', '2015-06-01'];
        yield 'custom range rejects' => ['debut_date', '1980-06-01', null];
        yield 'empty' => ['birth_date', '', null];
        yield 'null' => ['birth_date', null, null];
    }

    #[DataProvider('dates')]
    public function test_dates_are_iso_and_inside_their_range(string $field, mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer()->date($field, $raw, 'src', false));
    }

    /** @return iterable<string, array{mixed, ?string}> */
    public static function locations(): iterable
    {
        yield 'alias' => ['tokyo', 'Tokyo'];
        yield 'other script alias' => ['東京', 'Tokyo'];
        yield 'case and spaces' => [' Tok yo ', 'Tokyo'];
        yield 'second alias' => ['OSAKA', 'Osaka'];
        yield 'unknown place' => ['Nowhere', null];
        yield 'alias with empty canonical is ignored' => ['bad', null];
        yield 'junk' => ['unknown', null];
        yield 'not a string' => [12, null];
        yield 'empty' => ['', null];
        yield 'null' => [null, null];
    }

    #[DataProvider('locations')]
    public function test_location_maps_to_a_canonical_name(mixed $raw, ?string $expected): void
    {
        self::assertSame($expected, $this->normalizer()->location($raw, 'src', false));
    }

    public function test_facet_values_use_the_matching_rule_without_recording_rejections(): void
    {
        $normalizer = $this->normalizer();

        self::assertSame('D', $normalizer->facetValue('cup', 'd'));
        self::assertSame('AB', $normalizer->facetValue('blood_type', 'AB型'));
        self::assertSame('Tokyo', $normalizer->facetValue('location', '東京'));
        self::assertNull($normalizer->facetValue('hair', 'black'));
    }

    public function test_a_draft_is_normalised_field_by_field_and_keeps_its_identity(): void
    {
        $draft = new PerformerDraft(
            sourceSlug: 'src',
            externalId: '42',
            nameRomaji: 'Test Name',
            birthDate: '1995年4月5日',
            heightCm: 158,
            bust: 999,
            cup: 'dカップ',
            bloodType: 'A型',
            debutDate: '2015-06-01',
            location: '東京',
            bloodRh: 'Rh-',
        );

        $normalised = $this->normalizer()->normalizeDraft($draft, false);

        self::assertSame('src', $normalised->sourceSlug);
        self::assertSame('42', $normalised->externalId);
        self::assertSame('Test Name', $normalised->nameRomaji);
        self::assertSame('1995-04-05', $normalised->birthDate);
        self::assertSame(158, $normalised->heightCm);
        self::assertNull($normalised->bust, 'Out of range.');
        self::assertSame('D', $normalised->cup);
        self::assertSame('A', $normalised->bloodType);
        self::assertSame('2015-06-01', $normalised->debutDate);
        self::assertSame('Tokyo', $normalised->location);
        self::assertSame('-', $normalised->bloodRh);
    }

    public function test_the_rh_found_inside_the_blood_type_is_used_when_no_separate_rh_is_given(): void
    {
        $draft = new PerformerDraft(sourceSlug: 'src', externalId: '1', bloodType: 'AB+');

        self::assertSame('+', $this->normalizer()->normalizeDraft($draft, false)->bloodRh);
    }

    public function test_stored_attributes_are_normalised_the_same_way(): void
    {
        $stored = $this->normalizer()->normalizeStoredAttributes([
            'birth_date' => '19950405',
            'height_cm' => '158cm',
            'cup' => 'E',
            'blood_type' => 'O+',
            'location' => 'osaka',
            'debut_date' => '1980-01-01',
        ], 'src');

        self::assertSame('1995-04-05', $stored['birth_date']);
        self::assertSame(158, $stored['height_cm']);
        self::assertNull($stored['bust']);
        self::assertSame('E', $stored['cup']);
        self::assertSame('O', $stored['blood_type']);
        self::assertSame('+', $stored['blood_rh']);
        self::assertSame('Osaka', $stored['location']);
        self::assertNull($stored['debut_date'], 'Before the configured minimum.');
    }

    public function test_rules_default_to_the_application_configuration(): void
    {
        $normalizer = new PerformerAttributeNormalizer();

        self::assertSame('A', $normalizer->bloodType('A型', 'src', false)['value']);
    }

    public function test_the_location_index_is_built_once(): void
    {
        $normalizer = $this->normalizer();

        self::assertSame('Tokyo', $normalizer->location('tokyo', 'src', false));
        self::assertSame('Tokyo', $normalizer->location('東京', 'src', false));
    }
}
