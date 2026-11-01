<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('crawl_queue', function (Blueprint $table): void {
            $table->string('claimed_lane', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_queue', function (Blueprint $table): void {
            $table->dropColumn('claimed_lane');
        });
    }
};
