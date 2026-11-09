<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Design note:
 * - Purpose/access: retain one current on-demand search state per movie; lookups use movie_id, while operational
 *   state/cooldown scans use (state, cooldown_until). The unique movie_id index and state/cooldown index serve these
 *   access paths.
 * - Growth/retention: at most one row per movie that receives a search request; no attempt history is retained. Keep
 *   the latest row until its movie is deleted, when the foreign-key cascade removes it.
 * - Compatibility: this is an additive table; older releases ignore it and newer code reads it only after migration.
 * - Rollback: stop download-search workers, then drop the table; this loses only search state, not movie magnet rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('movie_download_searches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movie_id')->constrained('movies')->cascadeOnDelete();
            $table->string('state', 32)->default('idle');
            $table->unsignedInteger('found_count')->default(0);
            $table->json('sources_queued')->nullable();
            $table->json('sources_done')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('cooldown_until')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();
            $table->unique('movie_id');
            $table->index(['state', 'cooldown_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_download_searches');
    }
};
