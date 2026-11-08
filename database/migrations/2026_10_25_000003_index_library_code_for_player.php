<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('library_files', static function (Blueprint $t): void {
            $t->index(['code', 'missing_at']);
        });
    }
    public function down(): void
    {
        Schema::table('library_files', static function (Blueprint $t): void {
            $t->dropIndex(['code', 'missing_at']);
        });
    }
};
