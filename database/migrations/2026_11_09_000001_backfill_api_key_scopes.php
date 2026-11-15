<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Design note
 * Purpose: a key with no scopes used to mean "every scope"; the application now fails closed (no scopes, no access).
 *   This migration gives each key created before scopes existed the full scope list that "null" used to mean, so no
 *   existing integration loses access when the stricter rule is deployed. Operators narrow a key afterwards in the
 *   admin UI.
 * Access pattern: one update over api_keys, which is tiny (hundreds of rows, most of them revoked smoke-check keys).
 * Growth and retention: unchanged; no new column or index.
 * Compatibility: the previous release treats an explicit full list exactly like null, so rolling the code back is safe.
 * Rollback: nothing to undo; the original null values are not recoverable and are equivalent to the full list.
 * The list is a frozen copy of the scopes known when this migration was written, not a reference to the model.
 */
return new class extends Migration {
    private const FULL_ACCESS = ['movies', 'bulk', 'performers', 'catalog', 'status', 'config', 'mcp', 'media'];

    public function up(): void
    {
        DB::table('api_keys')->whereNull('scopes')->update(['scopes' => json_encode(self::FULL_ACCESS, JSON_THROW_ON_ERROR)]);
    }

    public function down(): void {}
};
