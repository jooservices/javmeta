<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Nullable, without a default: metadata-only addition on PostgreSQL; historical copied links stay null.
        Schema::table('performer_identity_event_movie_links', function (Blueprint $table): void {
            $table->unsignedBigInteger('evidence_source_performer_id')->nullable();
            $table->unsignedBigInteger('evidence_candidate_performer_id')->nullable();
        });
        Schema::create('performer_identity_event_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('performer_identity_events')->cascadeOnDelete();
            $table->unsignedBigInteger('review_id');
            $table->json('snapshot');
            $table->index(['event_id', 'id'], 'performer_identity_event_reviews_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_identity_event_reviews');
        Schema::table('performer_identity_event_movie_links', function (Blueprint $table): void {
            $table->dropColumn(['evidence_source_performer_id', 'evidence_candidate_performer_id']);
        });
    }
};
