<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Design note (UI API v2, downloads):
 * - download_requests: one row per "find me download links" ask of a signed-in user or a guest (requester = user id or a hash
 *   of the guest IP). It follows the movie's link search (queued, searching, found, failed, cancelled). Lookups use
 *   (requester, movie_code, state) and (requester, created_at).
 * - download_items gains the queue state the UI shows (state, progress, size, updated_at); movie_code becomes optional because
 *   an owner may queue a magnet that belongs to no known movie.
 * - Compatibility: additive; the existing DownloadService only inserts hash, movie_code, magnet and added_at, which the new
 *   defaults accept. Rollback drops the table and the columns (the queue state is lost, magnets stay).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('download_requests', static function (Blueprint $table): void {
            $table->id();
            $table->string('movie_code', 64);
            $table->string('requester', 80);
            $table->string('state', 12)->default('queued');
            $table->unsignedInteger('found_count')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('created_at');
            $table->timestampTz('last_attempt_at')->nullable();
            $table->index(['requester', 'movie_code', 'state']);
            $table->index(['requester', 'created_at']);
        });

        Schema::table('download_items', static function (Blueprint $table): void {
            $table->string('movie_code', 64)->nullable()->change();
            $table->string('state', 12)->default('queued');
            $table->unsignedSmallInteger('progress')->default(0);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('download_items', static function (Blueprint $table): void {
            $table->dropColumn(['state', 'progress', 'size_bytes', 'updated_at']);
        });

        Schema::dropIfExists('download_requests');
    }
};
