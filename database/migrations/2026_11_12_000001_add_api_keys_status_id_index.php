<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Purpose: index the active-key filter and descending id keyset order.
     * Access: UI active-key pages seek by status and id, returning at most 101 rows.
     * Growth: hundreds of keys initially; each issued key adds one entry, with history retained indefinitely.
     * Rollback: drop api_keys_status_id_index.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->index(['status', 'id'], 'api_keys_status_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table): void {
            $table->dropIndex('api_keys_status_id_index');
        });
    }
};
