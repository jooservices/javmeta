<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Contracts;

/** The audit writer used until the administration area binds the real one: it records nothing. */
final class NullAuditLog implements AuditLog
{
    public function record(?int $actorId, string $resource, string $action, array $detail = []): void {}
}
