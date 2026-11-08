<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public $withinTransaction = false;

    private const INDEXES = [
        'performers_name_romaji_lower_canonical_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_name_romaji_lower_canonical_idx ON performers (LOWER(name_romaji)) WHERE canonical_performer_id IS NULL',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_name_romaji_lower_canonical_idx',
        ],
        'performers_name_kanji_lower_canonical_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_name_kanji_lower_canonical_idx ON performers (LOWER(name_kanji)) WHERE canonical_performer_id IS NULL',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_name_kanji_lower_canonical_idx',
        ],
        'performers_name_kana_lower_canonical_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_name_kana_lower_canonical_idx ON performers (LOWER(name_kana)) WHERE canonical_performer_id IS NULL',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_name_kana_lower_canonical_idx',
        ],
        'performers_blood_type_lower_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_blood_type_lower_idx ON performers (LOWER(blood_type))',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_blood_type_lower_idx',
        ],
        'performers_location_lower_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_location_lower_idx ON performers (LOWER(location))',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_location_lower_idx',
        ],
        'performers_cup_lower_idx' => [
            'create' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS performers_cup_lower_idx ON performers (LOWER(cup))',
            'drop' => 'DROP INDEX CONCURRENTLY IF EXISTS performers_cup_lower_idx',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $index => $statements) {
            $existing = DB::selectOne(
                'SELECT CASE WHEN NOT i.indisvalid THEN 1 ELSE 0 END AS invalid FROM pg_class AS c JOIN pg_index AS i ON i.indexrelid = c.oid WHERE c.oid = to_regclass(?)',
                [$index],
            );

            if ((int) ($existing->invalid ?? 0) === 1) {
                DB::statement($statements['drop']);
            }

            DB::statement($statements['create']);
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::INDEXES as $statements) {
            DB::statement($statements['drop']);
        }
    }
};
