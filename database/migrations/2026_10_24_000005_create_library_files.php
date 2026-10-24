<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('library_files', static function (Blueprint $table): void {
            $table->id();
            $table->text('path')->unique();
            $table->text('folder');
            $table->unsignedBigInteger('size');
            $table->unsignedBigInteger('mtime');
            $table->string('quick_hash', 64)->nullable();
            $table->string('code', 64)->nullable();
            $table->unsignedBigInteger('movie_id')->nullable()->index();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('missing_at')->nullable();
            $table->index(['folder']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_files');
    }
};
