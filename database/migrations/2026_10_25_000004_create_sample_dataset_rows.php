<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sample_dataset_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('movie_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('performer_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->nullable()->unique()->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sample_dataset_rows');
    }
};
