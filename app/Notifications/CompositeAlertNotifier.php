<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\AlertAuditNotifier;
use App\Contracts\AlertNotifier;

final class CompositeAlertNotifier implements AlertNotifier
{
    /** @param list<AlertNotifier> $notifiers */
    public function __construct(private readonly array $notifiers) {}

    public function notify(string $subject, string $body, array $context = []): bool
    {
        $delivered = false;
        foreach ($this->notifiers as $notifier) {
            $notifierDelivered = $notifier->notify($subject, $body, $context);
            if (! $notifier instanceof AlertAuditNotifier && $notifierDelivered) {
                $delivered = true;
            }
        }

        return $delivered;
    }
}
