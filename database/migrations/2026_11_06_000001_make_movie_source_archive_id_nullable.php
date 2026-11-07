<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::table('movie_sources')
            ->where('mongo_id', 'like', 'unarchived:%')
            ->update(['mongo_id' => null]);

        Schema::table('movie_sources', static function (Blueprint $table): void {
            $table->text('mongo_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('movie_sources')
            ->whereNull('mongo_id')
            ->update(['mongo_id' => DB::raw("'unarchived:' || id")]);

        Schema::table('movie_sources', static function (Blueprint $table): void {
            $table->text('mongo_id')->nullable(false)->change();
        });
    }
};
