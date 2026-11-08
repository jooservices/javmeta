<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ui_login_states', function (Blueprint $table): void {
            $table->string('state_key', 64)->primary();
            $table->boolean('login_required')->nullable();
            $table->timestampTz('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ui_login_states');
    }
};
