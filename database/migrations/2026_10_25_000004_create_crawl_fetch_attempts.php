<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('created_at');
            $table->string('source_slug');
            // Keep diagnostics after the queue row is reclaimed or removed.
            $table->unsignedBigInteger('crawl_queue_id')->nullable();
            $table->char('url_hash', 64);
            $table->string('method', 64);
            $table->timestampTz('started_at', 3);
            $table->unsignedInteger('duration_ms');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('cf_mitigated')->nullable();
            $table->enum('outcome', ['ok', 'error', 'skipped']);
            $table->string('error_code', 64)->nullable();
            $table->text('error')->nullable();
            $table->index(['source_slug', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_fetch_attempts');
    }
};
