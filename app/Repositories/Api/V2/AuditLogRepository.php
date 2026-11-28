<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** The audit trail of changes made through the v2 API. */
final class AuditLogRepository
{
    /** @param array<string, mixed> $details */
    public function insert(string $actor, string $action, string $target, array $details): void
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
