<?php

declare(strict_types=1);

namespace App\Services\Alerts;

interface AlertChannel
{
    /** @param array<string, mixed> $context */
    public function send(string $subject, string $body, array $context = []): bool;
}
