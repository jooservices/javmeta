<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Log;
use Throwable;

final class AlertChannelDispatcher
{
    /** @param array<string, AlertChannel> $channels */
    public function __construct(private readonly array $channels) {}

    /**
     * @param array<string, mixed> $context
     * @return list<string> Names of channels that accepted the message.
     */
    public function dispatch(string $subject, string $body, array $context = [], bool $force = false): array
    {
        if (! $force && ! (bool) config('javmeta_alerts.enabled', true)) {
            return [];
        }

        $sent = [];
        foreach ($this->enabledNames() as $name) {
            $channel = $this->channels[$name] ?? null;
            if ($channel === null) {
                Log::warning('javmeta.alert_channel_unavailable', ['channel' => $name]);

                continue;
            }

            try {
                if ($channel->send($subject, $body, $context)) {
                    $sent[] = $name;
                }
            } catch (Throwable $exception) {
                Log::warning('javmeta.alert_channel_failed', [
                    'channel' => $name,
                    'exception' => $exception::class,
                ]);
            }
        }

        return $sent;
    }

    /** @return list<string> */
    public function enabledNames(): array
    {
        $names = array_values(array_unique(array_filter(
            array_map('strval', (array) config('javmeta_alerts.channels', ['telegram'])),
            static fn(string $name): bool => $name !== '',
        )));

        return $names;
    }
}
