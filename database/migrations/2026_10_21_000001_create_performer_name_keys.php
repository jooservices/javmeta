<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexed lookup of normalized source-performer names so identity matching on the crawl path
 * selects a few candidate roots instead of loading every canonical performer. Rows are filled
 * by `performers:backfill-identities` and kept current by identity reconciliation.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('performer_name_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_performer_id')->constrained('performers')->cascadeOnDelete();
            $table->text('normalized_name');
            $table->unique(['source_performer_id', 'normalized_name'], 'performer_name_keys_unique');
            $table->index('normalized_name', 'performer_name_keys_name_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_name_keys');
    }
};
