<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->index(['crawl_queue_id', 'started_at'], 'crawl_fetch_attempts_queue_started_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crawl_fetch_attempts', function (Blueprint $table): void {
            $table->dropIndex('crawl_fetch_attempts_queue_started_idx');
        });
    }
};
