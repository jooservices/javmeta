<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('node_enrollments')) {
            return;
        }

        Schema::create('node_enrollments', static function (Blueprint $table): void {
            $table->id();
            $table->string('node_name', 64);
            $table->string('role', 16);
            // Only the SHA-256 of the token is stored; the token itself is shown once.
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->string('used_from_ip', 45)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();
            $table->index(['node_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_enrollments');
    }
};
