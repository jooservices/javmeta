<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user state of the UI API (v2) that no older table holds.
 *
 * - ui_preferences: one row per user and preference key (the value is JSON), read at sign-in and changed by PATCH /me/preferences.
 * - view_history: what a user opened or clicked (movie, performer, photo, download); read newest first, cleared on request.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ui_preferences', static function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id');
            $table->string('key', 48);
            $table->json('value');
            $table->timestampTz('updated_at');

            $table->primary(['user_id', 'key']);
        });

        Schema::create('view_history', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('kind', 24);
            $table->text('ref');
            $table->string('label', 255)->nullable();
            $table->timestampTz('viewed_at');

            $table->index(['user_id', 'viewed_at']);
            $table->index(['user_id', 'kind', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_history');
        Schema::dropIfExists('ui_preferences');
    }
};
