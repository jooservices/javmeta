<?php

declare(strict_types=1);

namespace App\Services\Search;

/** Turns parsed search filters into short human-readable "Label: value" lines for the search-parse answer. */
final class FilterExplainer
{
    private const LABELS = [
        'maker' => 'Maker',
        'series' => 'Series',
        'genre' => 'Genre',
        'actress' => 'Performer',
        'blood_type' => 'Blood type',
        'location' => 'Location',
        'cup' => 'Cup size',
        'released_from' => 'Released on or after',
        'released_to' => 'Released on or before',
        'runtime_min' => 'Runtime at least (minutes)',
        'runtime_max' => 'Runtime at most (minutes)',
        'age_min' => 'Age at least',
        'age_max' => 'Age at most',
        'height_min' => 'Height at least (cm)',
        'height_max' => 'Height at most (cm)',
        'code' => 'Movie code',
    ];

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    public function explain(array $filters): array
    {
        $explanations = [];
        foreach ($filters as $field => $value) {
            $label = self::LABELS[$field] ?? ucfirst(str_replace('_', ' ', (string) $field));
            $explanations[$field] = $label . ': ' . (is_scalar($value) ? (string) $value : '');
        }

        return $explanations;
    }
}
