<?php

declare(strict_types=1);

$placeholderTokens = ['-', '–', '—', '−', '－', '不明', 'N/A', 'NA', 'NONE', 'UNKNOWN'];

return [
    'blood_types' => ['A', 'B', 'O', 'AB'],
    'cups' => ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'],
    'backfill_chunk_size' => 500,
    'junk_tokens' => [
        'blood_type' => [...$placeholderTokens, '- Type', '– Type', '型', 'Trans'],
        'blood_rh' => ['–', '—', '−', '－', '不明', 'N/A', 'NA', 'NONE', 'UNKNOWN'],
        'cup' => [...$placeholderTokens, '61', '100', '55', '65', 'FANZA', 'Q', 'T', 'Z'],
        'height_cm' => $placeholderTokens,
        'bust' => $placeholderTokens,
        'waist' => $placeholderTokens,
        'hip' => $placeholderTokens,
        'birth_date' => $placeholderTokens,
        'debut_date' => $placeholderTokens,
        'location' => $placeholderTokens,
    ],
    'field_ranges' => [
        'height_cm' => ['min' => 100, 'max' => 250],
        'bust' => ['min' => 50, 'max' => 250],
        'waist' => ['min' => 30, 'max' => 200],
        'hip' => ['min' => 50, 'max' => 250],
        'birth_date' => ['min' => '1900-01-01', 'max' => 'today'],
        'debut_date' => ['min' => '1950-01-01', 'max' => 'today'],
    ],
    'location_aliases' => require __DIR__ . '/javmeta_japan_locations.php',
];
