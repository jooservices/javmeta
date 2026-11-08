<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Serializes admission as well as claims, including when the queue is empty.
        Schema::create('media_worker_lock', static function (Blueprint $t): void {
            $t->unsignedInteger('id')->primary();
        });
        DB::table('media_worker_lock')->insert(['id' => 1]);
        Schema::create('media_jobs', static function (Blueprint $t): void {
            $t->id();
            $t->string('kind', 20);
            $t->foreignId('library_file_id')->nullable()->constrained('library_files');
            $t->text('source_url')->nullable();
            $t->json('params');
            $t->string('status', 12)->default('pending');
            $t->unsignedInteger('attempts')->default(0);
            $t->string('locked_by', 100)->nullable();
            $t->uuid('claim_token')->nullable();
            $t->json('result')->nullable();
            $t->text('error')->nullable();
            $t->timestampTz('created_at');
            $t->timestampTz('started_at')->nullable();
            $t->timestampTz('finished_at')->nullable();
            $t->index(['status', 'id']);
            $t->index(['library_file_id', 'kind', 'status']);
        });
        Schema::create('media_scenes', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('library_file_id')->constrained('library_files');
            $t->unsignedBigInteger('movie_id')->nullable()->index();
            $t->double('start_sec');
            $t->double('end_sec');
            $t->text('thumb_path');
            $t->text('description')->nullable();
            $t->json('tags')->nullable();
            $t->double('confidence')->nullable();
            $t->timestampTz('embedding_indexed_at')->nullable();
            $t->unique(['library_file_id', 'start_sec']);
        });
        Schema::create('media_subtitles', static function (Blueprint $t): void {
            $t->id();
            $t->foreignId('library_file_id')->constrained('library_files');
            $t->string('language', 20);
            $t->string('format', 3);
            $t->text('path');
            $t->string('source', 12);
            $t->timestampTz('created_at');
            $t->unique(['library_file_id', 'language', 'format', 'source']);
        });
    }

    public function down(): void
    {
        foreach (['media_subtitles', 'media_scenes', 'media_jobs', 'media_worker_lock'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
