<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Contracts\AlertNotifier;
use App\Contracts\ReportChannel;
use App\Observability\TelemetrySanitizer;
use App\Services\Notifications\NotificationRenderer;
use Illuminate\Support\Facades\Log;
use JOOservices\Client\Client\ClientBuilder;
use JOOservices\Client\Client\HttpClient;
use JOOservices\Client\Response\Response;
use Throwable;

/**
 * App-facing alert adapter: renders javmeta context into a package Message and
 * sends via jooservices/laravel-notifications TelegramChannel. Templates,
 * debounce, and Log stay in the application layer.
 */
final class TelegramAlertNotifier implements AlertNotifier, ReportChannel
{
    public function __construct(
        private readonly ?HttpClient $httpClient = null,
        private readonly ?NotificationRenderer $renderer = null,
    ) {}

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
        $templateKey = is_string($context['template_key'] ?? null) ? $context['template_key'] : null;
        if ($templateKey !== null && ! ($context['template_rendered'] ?? false)) {
            $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render($templateKey, $context);
            $subject = $rendered['title'];
            $body = $rendered['body'];
        }
        $templateRendered = (bool) ($context['template_rendered'] ?? false) || $templateKey !== null;

        if (isset($context['alert_state'])) {
            if (! $templateRendered) {
                $resolved = strtoupper((string) $context['alert_state']) === 'RESOLVED';
                $severity = strtoupper((string) ($context['severity'] ?? 'warning'));
                $level = $resolved ? 'RESOLVED' : (in_array($severity, ['INFO', 'NOTICE', 'WARNING', 'CRITICAL'], true) ? $severity : 'WARNING');
                $subject = "[{$level}] {$subject}";
            }
            unset($context['alert_state'], $context['severity'], $context['template_rendered']);
        }
        if (! $templateRendered) {
            $sanitizer = app(TelemetrySanitizer::class);
            $subject = $sanitizer->sanitizeText($subject) ?? '';
            $body = $sanitizer->sanitizeText($body) ?? '';
            $subject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $body = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            foreach ($this->scalarContext($context) as $key => $value) {
                $safeValue = $sanitizer->sanitizeText($value === null ? 'null' : (string) $value) ?? '';
                $body .= "\n" . htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ': '
                    . htmlspecialchars($safeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
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

        if ($token === '' || $chatId === '') {
            Log::warning('javmeta.telegram_skipped', [
                'reason' => 'TELEGRAM bot token or chat id missing',
            ]);

            return false;
        }

        try {
            $client = $this->httpClient ?? ClientBuilder::create()
                ->withBaseUri(rtrim($apiBase !== '' ? $apiBase : 'https://api.telegram.org', '/') . '/')
                ->withTimeout($timeout > 0 ? $timeout : 5.0)
                ->build();
            $message = ($templateRendered ? $subject : $this->prefixed($subject)) . "\n" . $body;
            $prepared = $client->requestBuilder()
                ->post('/bot' . $token . '/sendMessage')
                ->withJson([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ])
                ->build();
            $response = Response::from($client->sendRequest($prepared->toPsr()));
            if (! $response->successful()) {
                Log::warning('javmeta.telegram_failed', [
                    'reason' => 'Telegram API returned HTTP ' . $response->status(),
                ]);

                return false;
            }
        } catch (Throwable) {
            Log::warning('javmeta.telegram_failed', [
                'reason' => 'Telegram transport failed.',
            ]);

            return false;
        }

        if ($templateKey !== null && ! ($context['test_send'] ?? false)) {
            ($this->renderer ?? app(NotificationRenderer::class))->markSent($templateKey);
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

    /** @param array<string, mixed> $context
     *  @return array<string, string|int|float|bool|null>
     */
    private function scalarContext(array $context): array
    {
        $scalars = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $scalars[$key] = $value;
            }
        }

        return $scalars;
    }
}
