<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Search;

use App\Services\Search\FilterExplainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FilterExplainer::class)]
final class FilterExplainerTest extends TestCase
{
    public function test_every_known_filter_gets_its_label(): void
    {
        $labels = [
            'maker' => 'Maker', 'series' => 'Series', 'genre' => 'Genre', 'actress' => 'Performer',
            'blood_type' => 'Blood type', 'location' => 'Location', 'cup' => 'Cup size',
            'released_from' => 'Released on or after', 'released_to' => 'Released on or before',
            'runtime_min' => 'Runtime at least (minutes)', 'runtime_max' => 'Runtime at most (minutes)',
            'age_min' => 'Age at least', 'age_max' => 'Age at most',
            'height_min' => 'Height at least (cm)', 'height_max' => 'Height at most (cm)',
            'code' => 'Movie code',
        ];

        $explained = (new FilterExplainer())->explain(array_fill_keys(array_keys($labels), 'x'));

        foreach ($labels as $field => $label) {
            self::assertSame($label . ': x', $explained[$field], $field);
        }
    }

    public function test_an_unknown_filter_is_labelled_from_its_name_and_non_scalar_values_are_left_blank(): void
    {
        $explained = (new FilterExplainer())->explain(['hair_color' => 'black', 'age_min' => 20, 'maker' => ['a', 'b']]);

        self::assertSame('Hair color: black', $explained['hair_color']);
        self::assertSame('Age at least: 20', $explained['age_min']);
        self::assertSame('Maker: ', $explained['maker']);
        self::assertSame([], (new FilterExplainer())->explain([]));
    }
}
