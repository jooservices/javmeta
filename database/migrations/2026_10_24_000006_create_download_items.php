<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('download_items', static function (Blueprint $table): void {
            $table->id();
            $table->string('hash', 64)->unique();
            $table->string('movie_code', 64)->index();
            $table->text('magnet');
            $table->timestampTz('added_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_items');
    }
};
