<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed name trigrams of source performers. The identity backfill selects the few candidate roots that
 * share a trigram with a source from this table instead of holding the whole catalog in memory.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('performer_name_grams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_performer_id')->constrained('performers')->cascadeOnDelete();
            $table->string('gram', 8);
            $table->unique(['source_performer_id', 'gram'], 'performer_name_grams_unique');
            $table->index('gram', 'performer_name_grams_gram_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_name_grams');
    }
};
