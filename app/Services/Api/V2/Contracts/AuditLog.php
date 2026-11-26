<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Contracts;

use Illuminate\Container\Attributes\Bind;

/** Writes one row of the audit trail for a change made through the v2 API. The administration area provides the real writer. */
#[Bind(NullAuditLog::class)]
interface AuditLog
{
    /** @param array<string, mixed> $detail */
    public function record(?int $actorId, string $resource, string $action, array $detail = []): void;
}
