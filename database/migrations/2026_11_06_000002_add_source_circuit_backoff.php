<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->unsignedInteger('circuit_open_count')->default(0);
            $table->unsignedInteger('circuit_cooldown_seconds')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn(['circuit_open_count', 'circuit_cooldown_seconds']);
        });
    }
};
