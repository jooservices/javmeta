<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Carbon\CarbonImmutable;

/** What the Command Center shows once after creating an enrollment. The token is not recoverable afterwards. */
final readonly class EnrollmentGrant
{
    public function __construct(
        public int $enrollmentId,
        public string $nodeName,
        public string $role,
        public string $token,
        public string $command,
        public CarbonImmutable $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->enrollmentId,
            'node_name' => $this->nodeName,
            'role' => $this->role,
            'token' => $this->token,
            'command' => $this->command,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
