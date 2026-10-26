<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    private const INDEXES = [
        'performers_name_romaji_lower_canonical_idx' => ['name_romaji', 'canonical_performer_id IS NULL'],
        'performers_name_kanji_lower_canonical_idx' => ['name_kanji', 'canonical_performer_id IS NULL'],
        'performers_name_kana_lower_canonical_idx' => ['name_kana', 'canonical_performer_id IS NULL'],
        'performers_blood_type_lower_idx' => ['blood_type', "blood_type IS NOT NULL AND blood_type <> ''"],
        'performers_location_lower_idx' => ['location', "location IS NOT NULL AND location <> ''"],
        'performers_cup_lower_idx' => ['cup', "cup IS NOT NULL AND cup <> ''"],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => [$column, $predicate]) {
            DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON performers (LOWER({$column})) WHERE {$predicate}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys(self::INDEXES) as $index) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$index}");
        }
    }
};
