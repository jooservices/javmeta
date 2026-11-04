<?php

declare(strict_types=1);

namespace App\Events;

/** A super administrator created a one-time permission for a node to join. Carries no secret. */
final readonly class NodeEnrollmentCreated
{
    public function __construct(public int $enrollmentId, public string $nodeName, public string $role, public ?int $createdBy) {}
}
