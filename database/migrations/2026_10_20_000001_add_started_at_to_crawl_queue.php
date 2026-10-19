<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('crawl_queue', 'started_at')) {
            return;
        }

        Schema::table('crawl_queue', function (Blueprint $table): void {
            $table->timestampTz('started_at')->nullable();
        });

        DB::table('crawl_queue')
            ->where('status', 'claimed')
            ->whereNull('started_at')
            ->whereNotNull('claimed_at')
            ->update(['started_at' => DB::raw('claimed_at')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('crawl_queue', 'started_at')) {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->dropColumn('started_at');
            });
        }
    }
};
