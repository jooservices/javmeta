<?php

declare(strict_types=1);

namespace App\Contracts;

interface AlertNotifier
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function notify(string $subject, string $body, array $context = []): bool;
}
