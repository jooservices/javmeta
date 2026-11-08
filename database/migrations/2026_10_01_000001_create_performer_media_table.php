<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $isPostgres = DB::getDriverName() === 'pgsql';

        Schema::create('performer_media', function (Blueprint $table) use ($isPostgres): void {
            $table->id();
            $table->foreignId('performer_id')->constrained('performers');
            $table->text('kind');
            $table->text('url');
            if ($isPostgres) {
                $table->jsonb('meta')->nullable();
            } else {
                $table->json('meta')->nullable();
            }
            $table->text('source_slug');
            $table->timestampTz('crawled_at');

            $table->unique(['performer_id', 'kind', 'url']);
            $table->index(
                ['performer_id', 'kind', 'source_slug'],
                'performer_media_performer_kind_source_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performer_media');
    }
};
