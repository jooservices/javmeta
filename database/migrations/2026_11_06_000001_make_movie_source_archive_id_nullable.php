<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Design note:
 * - Purpose: allow movie provenance to exist when Mongo archival is unavailable.
 * - Access and indexes: rows remain unique by (movie_id, site_slug) and
 *   (site_slug, mongo_id); both indexes are unchanged and serve the existing
 *   movie/source and archive lookups.
 * - Growth and retention: one row per movie/source; this migration adds no
 *   rows, and provenance remains with its movie under the existing retention.
 * - Compatibility: the text column and unique indexes remain in place. Make
 *   it nullable before clearing legacy placeholders so the update is valid.
 * - Rollback: down() restores unique unarchived:<id> values before NOT NULL.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('movie_sources', static function (Blueprint $table): void {
            $table->text('mongo_id')->nullable()->change();
        });

        DB::table('movie_sources')
            ->where('mongo_id', 'like', 'unarchived:%')
            ->update(['mongo_id' => null]);
    }

    public function down(): void
    {
        DB::table('movie_sources')
            ->whereNull('mongo_id')
            ->update(['mongo_id' => DB::raw("'unarchived:' || id")]);

        Schema::table('movie_sources', static function (Blueprint $table): void {
            $table->text('mongo_id')->nullable(false)->change();
        });
    }
};
