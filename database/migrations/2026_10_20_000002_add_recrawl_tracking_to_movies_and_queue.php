<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('movies', 'recrawl_attempts') || ! Schema::hasColumn('movies', 'recrawl_status')) {
            Schema::table('movies', function (Blueprint $table): void {
                if (! Schema::hasColumn('movies', 'recrawl_attempts')) {
                    $table->unsignedSmallInteger('recrawl_attempts')->default(0);
                }

                if (! Schema::hasColumn('movies', 'recrawl_status')) {
                    $table->text('recrawl_status')->nullable();
                }
            });
        }

        if (! Schema::hasColumn('crawl_queue', 'is_recrawl')) {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->boolean('is_recrawl')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crawl_queue', 'is_recrawl')) {
            Schema::table('crawl_queue', function (Blueprint $table): void {
                $table->dropColumn('is_recrawl');
            });
        }

        $movieColumns = array_values(array_filter(
            ['recrawl_attempts', 'recrawl_status'],
            static fn(string $column): bool => Schema::hasColumn('movies', $column),
        ));

        if ($movieColumns !== []) {
            Schema::table('movies', function (Blueprint $table) use ($movieColumns): void {
                $table->dropColumn($movieColumns);
            });
        }
    }
};
