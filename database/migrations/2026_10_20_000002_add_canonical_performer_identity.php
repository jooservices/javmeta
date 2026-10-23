<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('performers', function (Blueprint $table): void {
            $table->foreignId('canonical_performer_id')->nullable()->after('id')
                ->constrained('performers')->nullOnDelete();
            $table->index('canonical_performer_id', 'performers_canonical_performer_idx');
        });

        Schema::table('performer_sources', function (Blueprint $table): void {
            $table->foreignId('source_performer_id')->nullable()->after('performer_id')
                ->constrained('performers')->cascadeOnDelete();
            $table->text('name_romaji')->nullable();
            $table->text('name_kanji')->nullable();
            $table->text('name_kana')->nullable();
            $table->date('birth_date')->nullable();
            $table->text('cross_link_source')->nullable();
            $table->text('cross_link_id')->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->index('source_performer_id', 'performer_sources_source_performer_idx');
            $table->index(['cross_link_source', 'cross_link_id'], 'performer_sources_cross_link_idx');
        });

        // One set-based statement per table keeps the migration bounded at catalog scale.
        $columns = ['name_romaji', 'name_kanji', 'name_kana', 'birth_date'];
        $sourceUpdate = ['source_performer_id' => DB::raw('performer_id')];
        foreach ($columns as $column) {
            $sourceUpdate[$column] = DB::raw("(SELECT p.{$column} FROM performers p WHERE p.id = performer_sources.performer_id)");
        }
        DB::table('performer_sources')->whereExists(static function ($query): void {
            $query->selectRaw('1')->from('performers')->whereColumn('performers.id', 'performer_sources.performer_id');
        })->update($sourceUpdate);

        Schema::table('performer_aliases', function (Blueprint $table): void {
            $table->text('source_slug')->nullable();
            $table->foreignId('source_performer_id')->nullable()->constrained('performers')->cascadeOnDelete();
            $table->dropUnique(['performer_id', 'alias']);
            $table->unique(
                ['performer_id', 'alias', 'source_performer_id'],
                'performer_aliases_source_unique',
            );
            $table->index('source_performer_id', 'performer_aliases_source_performer_idx');
        });

        DB::table('performer_aliases')->update([
            'source_slug' => DB::raw('(SELECT p.source_slug FROM performers p WHERE p.id = performer_aliases.performer_id)'),
            'source_performer_id' => DB::raw('performer_id'),
        ]);

        Schema::table('performer_media', function (Blueprint $table): void {
            $table->dropUnique(['performer_id', 'kind', 'url']);
            $table->foreignId('source_performer_id')->nullable()->constrained('performers')->cascadeOnDelete();
            $table->unique(
                ['performer_id', 'kind', 'url', 'source_performer_id'],
                'performer_media_source_unique',
            );
            $table->index('source_performer_id', 'performer_media_source_performer_idx');
        });

        DB::table('performer_media')->whereNull('source_performer_id')->update([
            'source_performer_id' => DB::raw('performer_id'),
        ]);

        Schema::create('performer_identity_events', function (Blueprint $table): void {
            $table->id();
            $table->text('event_type');
            $table->foreignId('from_performer_id')->constrained('performers');
            $table->foreignId('to_performer_id')->constrained('performers');
            $table->foreignId('related_event_id')->nullable()->constrained('performer_identity_events');
            $table->json('details');
            $table->timestampTz('created_at');
            $table->timestampTz('reversed_at')->nullable();
        });

        Schema::create('performer_identity_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_performer_id')->constrained('performers');
            $table->foreignId('candidate_performer_id')->constrained('performers');
            $table->text('reason');
            $table->decimal('similarity', 5, 4);
            $table->text('status')->default('open');
            $table->timestampTz('created_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->unique(
                ['source_performer_id', 'candidate_performer_id', 'status'],
                'performer_identity_reviews_pair_status_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_identity_reviews');
        Schema::dropIfExists('performer_identity_events');

        Schema::table('performer_aliases', function (Blueprint $table): void {
            $table->dropIndex('performer_aliases_source_performer_idx');
            $table->dropUnique('performer_aliases_source_unique');
            $duplicates = DB::table('performer_aliases')->select('performer_id', 'alias')
                ->groupBy('performer_id', 'alias')->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                $keepId = DB::table('performer_aliases')->where('performer_id', $duplicate->performer_id)
                    ->where('alias', $duplicate->alias)->min('id');
                DB::table('performer_aliases')->where('performer_id', $duplicate->performer_id)
                    ->where('alias', $duplicate->alias)->where('id', '<>', $keepId)->delete();
            }
            $table->unique(['performer_id', 'alias']);
            $table->dropConstrainedForeignId('source_performer_id');
            $table->dropColumn('source_slug');
        });

        Schema::table('performer_media', function (Blueprint $table): void {
            $table->dropIndex('performer_media_source_performer_idx');
            $table->dropUnique('performer_media_source_unique');
            $duplicates = DB::table('performer_media')->select('performer_id', 'kind', 'url')
                ->groupBy('performer_id', 'kind', 'url')->havingRaw('COUNT(*) > 1')->get();
            foreach ($duplicates as $duplicate) {
                $keepId = DB::table('performer_media')->where('performer_id', $duplicate->performer_id)
                    ->where('kind', $duplicate->kind)->where('url', $duplicate->url)->min('id');
                DB::table('performer_media')->where('performer_id', $duplicate->performer_id)
                    ->where('kind', $duplicate->kind)->where('url', $duplicate->url)
                    ->where('id', '<>', $keepId)->delete();
            }
            $table->unique(['performer_id', 'kind', 'url']);
            $table->dropConstrainedForeignId('source_performer_id');
        });

        Schema::table('performer_sources', function (Blueprint $table): void {
            $table->dropIndex('performer_sources_cross_link_idx');
            $table->dropIndex('performer_sources_source_performer_idx');
            $table->dropConstrainedForeignId('source_performer_id');
            $table->dropColumn([
                'name_romaji', 'name_kanji', 'name_kana', 'birth_date',
                'cross_link_source', 'cross_link_id', 'removed_at',
            ]);
        });

        Schema::table('performers', function (Blueprint $table): void {
            $table->dropIndex('performers_canonical_performer_idx');
            $table->dropConstrainedForeignId('canonical_performer_id');
        });
    }
};
