<?php

declare(strict_types=1);

namespace App\Services\Dependencies;

final readonly class ProbeResult
{
    public function __construct(
        public ?bool $available,
        public string $message,
    ) {}
}
