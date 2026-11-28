<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Audit trail of administrator changes (v2 API) and the revision counter of each setting. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_log', static function (Blueprint $table): void {
            $table->id();
            $table->string('actor', 255);
            $table->string('action', 64);
            $table->string('target', 255);
            $table->text('details');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['target', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('setting_revisions', static function (Blueprint $table): void {
            $table->string('key', 160)->primary();
            $table->unsignedInteger('revision')->default(1);
            $table->timestampTz('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_revisions');
        Schema::dropIfExists('audit_log');
    }
};
