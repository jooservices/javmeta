<?php

declare(strict_types=1);

namespace App\Events;

/** A node redeemed its token and received the join bundle. Carries no secret. */
final readonly class NodeEnrolled
{
    public function __construct(public int $enrollmentId, public string $nodeName, public string $role, public string $ip) {}
}
