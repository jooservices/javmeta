<?php

declare(strict_types=1);

namespace App\Observability\Sentry;

use Sentry\Event;
use Sentry\State\Scope;

final class SentryContext
{
    /**
     * @return array{role: string, node: string}
     */
    public static function tags(): array
    {
        return [
            'role' => (string) config('sentry.role', 'api'),
            'node' => (string) config('sentry.node', 'unknown'),
        ];
    }

    public static function applyToScope(Scope $scope): void
    {
        foreach (self::tags() as $key => $value) {
            $scope->setTag($key, $value);
        }
    }

    public static function applyToEvent(Event $event): Event
    {
        foreach (self::tags() as $key => $value) {
            $event->setTag($key, $value);
        }

        return $event;
    }
}
