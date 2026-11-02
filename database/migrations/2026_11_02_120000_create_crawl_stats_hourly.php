<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('crawl_stats_hourly', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('hour_at');
            $table->string('source_slug', 128);
            $table->string('lane', 32);
            $table->string('method', 64);
            $table->unsignedBigInteger('attempts')->default(0);
            $table->unsignedBigInteger('ok')->default(0);
            $table->unsignedBigInteger('blocked')->default(0);
            $table->unsignedBigInteger('timeout')->default(0);
            $table->unsignedBigInteger('other_error')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->decimal('avg_duration_ms', 14, 2)->nullable();
            $table->unsignedInteger('p90_duration_ms')->nullable();
            $table->unsignedBigInteger('bytes_total')->nullable();

            $table->unsignedBigInteger('movies_new')->nullable();
            $table->unsignedBigInteger('movies_updated')->nullable();
            $table->unsignedBigInteger('performers_new')->nullable();
            $table->unsignedBigInteger('jobs_failed')->nullable();
            $table->decimal('queue_ready_avg', 14, 2)->nullable();
            $table->decimal('queue_delayed_avg', 14, 2)->nullable();
            $table->decimal('queue_reserved_avg', 14, 2)->nullable();
            $table->decimal('playwright_busy_ratio_avg', 8, 6)->nullable();
            $table->decimal('flaresolverr_busy_ratio_avg', 8, 6)->nullable();
            $table->decimal('postgres_connections_avg', 14, 2)->nullable();
            $table->decimal('node_cpu_avg', 8, 6)->nullable();
            $table->unsignedInteger('queue_samples')->default(0);
            $table->unsignedInteger('playwright_samples')->default(0);
            $table->unsignedInteger('flaresolverr_samples')->default(0);
            $table->unsignedInteger('postgres_samples')->default(0);
            $table->timestampsTz();

            $table->unique(['hour_at', 'source_slug', 'lane', 'method'], 'crawl_stats_hourly_dimensions_unique');
            $table->index('hour_at', 'crawl_stats_hourly_hour_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_stats_hourly');
    }
};
