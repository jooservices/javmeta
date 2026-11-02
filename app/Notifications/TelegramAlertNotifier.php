<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\AlertNotifier;
use App\Contracts\ReportChannel;
use Illuminate\Support\Facades\Log;
use JOOservices\Client\Client\HttpClient;
use JOOservices\LaravelNotifications\Channels\TelegramChannel;
use JOOservices\LaravelNotifications\Message;

/**
 * App-facing alert adapter: renders javmeta context into a package Message and
 * sends via jooservices/laravel-notifications TelegramChannel. Templates,
 * debounce, and Log stay in the application layer.
 */
final class TelegramAlertNotifier implements AlertNotifier, ReportChannel
{
    public function __construct(private readonly ?HttpClient $httpClient = null) {}

    /**
     * Send an alert, defaulting to a warning while preserving the explicit caller severity and resolved state.
     *
     * @param  array<string, mixed>  $context
     */
    public function notify(string $subject, string $body, array $context = []): void
    {
        $this->deliver($subject, $body, $context + ['alert_state' => 'open', 'severity' => 'warning']);
    }

    /**
     * Render alert metadata and deliver the Telegram message.
     *
     * @param  array<string, mixed>  $context
     */
    public function deliver(string $subject, string $body, array $context = []): bool
    {
        if (isset($context['alert_state'])) {
            $resolved = strtoupper((string) $context['alert_state']) === 'RESOLVED';
            $severity = strtoupper((string) ($context['severity'] ?? 'warning'));
            $level = $resolved ? 'RESOLVED' : (in_array($severity, ['INFO', 'NOTICE', 'WARNING', 'CRITICAL'], true) ? $severity : 'WARNING');
            unset($context['alert_state'], $context['severity']);
            $subject = "[{$level}] {$subject}";
        }

        $token = $this->stringConfig('laravel-notifications.channels.telegram.bot_token')
            ?: $this->stringConfig('javmeta_alerts.telegram.bot_token');
        $chatId = $this->stringConfig('laravel-notifications.channels.telegram.chat_id')
            ?: $this->stringConfig('javmeta_alerts.telegram.chat_id');
        $apiBase = $this->stringConfig('laravel-notifications.channels.telegram.api_base')
            ?: $this->stringConfig('javmeta_alerts.telegram.api_base', 'https://api.telegram.org');
        $timeout = (float) (
            config('laravel-notifications.channels.telegram.timeout')
            ?? config('javmeta_alerts.telegram.timeout', 5)
        );

        $channel = new TelegramChannel(
            botToken: $token,
            chatId: $chatId,
            apiBase: $apiBase !== '' ? $apiBase : 'https://api.telegram.org',
            timeout: $timeout > 0 ? $timeout : 5.0,
            client: $this->httpClient,
        );

        $result = $channel->send(
            Message::make(
                subject: $this->prefixed($subject),
                body: $body,
                context: $this->scalarContext($context),
            ),
        );

        if ($result->isSkipped()) {
            Log::warning('javmeta.telegram_skipped', [
                'reason' => $result->reason ?? 'skipped',
                'subject' => $subject,
            ]);

            return false;
        }

        if ($result->isFailed()) {
            Log::warning('javmeta.telegram_failed', [
                'reason' => $result->reason ?? 'failed',
                'subject' => $subject,
            ]);

            return false;
        }

        return true;
    }

    /** Message envelope: "[XCrawler][LEVEL] title" for alerts and "[XCrawler] title" for plain messages. */
    private function prefixed(string $subject): string
    {
        return '[XCrawler]' . (str_starts_with($subject, '[') ? '' : ' ') . $subject;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, string|int|float|bool|null>
     */
    private function scalarContext(array $context): array
    {
        $scalars = [];

        foreach ($context as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $scalars[$key] = $value;
        }

        return $scalars;
    }
}
