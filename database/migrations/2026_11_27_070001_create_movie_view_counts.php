<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One counter per movie code: how many times the UI opened it (guests included). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('movie_view_counts', static function (Blueprint $table): void {
            $table->string('code', 64)->primary();
            $table->unsignedBigInteger('views')->default(0);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movie_view_counts');
    }
};
