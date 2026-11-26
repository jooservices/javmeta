<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Services\Api\V2\Contracts\AuditLog;
use Illuminate\Support\Facades\DB;

/** The audit trail kept in the audit_log table. */
final class DatabaseAuditLog implements AuditLog
{
    /** @param array<string, mixed> $details */
    public function record(string $actor, string $action, string $target, array $details = []): void
    {
        DB::table('audit_log')->insert([
            'actor' => $actor,
            'action' => $action,
            'target' => $target,
            'details' => json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }
}
