<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One editable template row per (key, locale). Existing rows become locale=en so the default
 * sent text stays English until the owner switches notifications.locale.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('notification_templates', static function (Blueprint $table): void {
            $table->string('locale', 5)->default('en')->after('key');
        });

        // Drop the old unique(key) and replace with unique(key, locale).
        Schema::table('notification_templates', static function (Blueprint $table): void {
            $table->dropUnique(['key']);
            $table->unique(['key', 'locale']);
        });

        DB::table('notification_templates')->whereNull('locale')->orWhere('locale', '')->update(['locale' => 'en']);
    }

    public function down(): void
    {
        // Keep only English rows before restoring the single-key unique constraint.
        DB::table('notification_templates')->where('locale', '!=', 'en')->delete();

        Schema::table('notification_templates', static function (Blueprint $table): void {
            $table->dropUnique(['key', 'locale']);
            $table->unique(['key']);
            $table->dropColumn('locale');
        });
    }
};
