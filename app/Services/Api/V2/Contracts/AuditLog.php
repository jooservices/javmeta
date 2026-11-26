<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Contracts;

/** Writes one row of the audit trail: who did what to which resource. */
interface AuditLog
{
    /** @param array<string, mixed> $details */
    public function record(string $actor, string $action, string $target, array $details = []): void;
}
