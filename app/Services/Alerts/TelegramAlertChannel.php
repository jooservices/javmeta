<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Notifications\TelegramAlertNotifier;

final class TelegramAlertChannel implements AlertChannel
{
    public function __construct(private readonly TelegramAlertNotifier $telegram) {}

    /** @param array<string, mixed> $context */
    public function send(string $subject, string $body, array $context = []): bool
    {
        return $this->telegram->deliver($subject, $body, $context);
    }
}
