<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Administration;

use App\Repositories\Api\V2\AuditLogRepository;
use App\Services\Api\V2\Contracts\AuditLog;

/** The audit trail kept in the audit_log table. */
final class DatabaseAuditLog implements AuditLog
{
    public function __construct(private readonly AuditLogRepository $rows) {}

    /** @param array<string, mixed> $details */
    public function record(string $actor, string $action, string $target, array $details = []): void
    {
        $this->rows->insert($actor, $action, $target, $details);
    }
}
