<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ([['performers', 'bio_text'], ['performer_sources', 'name_romaji'], ['performer_sources', 'name_kanji'], ['performer_sources', 'name_kana']] as [$table, $column]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$column}_trgm_gin_idx ON {$table} USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['performers_bio_text', 'performer_sources_name_romaji', 'performer_sources_name_kanji', 'performer_sources_name_kana'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}_trgm_gin_idx");
        }
    }
};
