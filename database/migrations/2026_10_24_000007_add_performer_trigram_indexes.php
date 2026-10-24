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
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        foreach ([['performers', 'name_romaji'], ['performers', 'name_kanji'], ['performers', 'name_kana'], ['performer_aliases', 'alias']] as [$table, $column]) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$table}_{$column}_trgm_gin_idx ON {$table} USING gin ({$column} gin_trgm_ops)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (['performers_name_romaji', 'performers_name_kanji', 'performers_name_kana', 'performer_aliases_alias'] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}_trgm_gin_idx");
        }
    }
};
