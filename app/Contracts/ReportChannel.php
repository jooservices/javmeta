<?php

declare(strict_types=1);

namespace App\Contracts;

interface ReportChannel
{
    /**
     * Deliver one report message. Returns true only when the message really left.
     *
     * @param  array<string, mixed>  $context
     */
    public function deliver(string $subject, string $body, array $context = []): bool;
}
