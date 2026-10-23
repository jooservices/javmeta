<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Movie links a merge copied to the canonical performer. Split and undo read them in pages instead of one
 * unbounded JSON value in performer_identity_events.details.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('performer_identity_event_movie_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('performer_identity_events')->cascadeOnDelete();
            $table->unsignedBigInteger('movie_id');
            $table->text('source_slug');
            $table->timestampTz('crawled_at');
            $table->index(['event_id', 'id'], 'performer_identity_event_movie_links_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_identity_event_movie_links');
    }
};
