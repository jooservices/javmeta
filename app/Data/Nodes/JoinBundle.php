<?php

declare(strict_types=1);

namespace App\Data\Nodes;

/** The installer answers a joining worker needs. It is handed over once, over HTTPS, and never logged. */
final readonly class JoinBundle
{
    /** @param array<string, string> $answers installer key => value */
    public function __construct(public string $nodeName, public string $role, public array $answers) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['node_name' => $this->nodeName, 'role' => $this->role, 'answers' => $this->answers];
    }
}
