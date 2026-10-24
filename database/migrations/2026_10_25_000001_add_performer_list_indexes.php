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
        DB::statement('CREATE INDEX IF NOT EXISTS performer_sources_performer_list_idx ON performer_sources (performer_id)');
        DB::statement("CREATE INDEX IF NOT EXISTS performers_display_name_list_idx ON performers ((COALESCE(name_romaji, name_kanji, name_kana, '')), id) WHERE canonical_performer_id IS NULL");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS performers_display_name_list_idx');
        DB::statement('DROP INDEX IF EXISTS performer_sources_performer_list_idx');
    }
};
