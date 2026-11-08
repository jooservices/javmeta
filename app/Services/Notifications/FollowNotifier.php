<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Downloads\DownloadService;
use App\Services\Settings\SettingsService;
use App\Repositories\NotificationDeliveryServiceRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one Telegram message for each new movie of a followed performer, maker or series, with the cover, the title, the
 * reason and an Open button. Quiet hours hold messages back until they end; a daily cap stops a flood. Feature switches
 * and limits are database settings; the bot token and chat id come from runtime secrets.
 */
final class FollowNotifier
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly DownloadService $downloads,
        private readonly NotificationDeliveryServiceRepository $repository,
        private readonly ?NotificationRenderer $renderer = null,
    ) {}

    /** @return array{sent: int, held: int, skipped: string|null} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now((string) config('javmeta_alerts.hourly_report.timezone', 'America/Mexico_City'));
        if (! (bool) $this->settings->get('follow_notifications.enabled')) {
            return ['sent' => 0, 'held' => 0, 'skipped' => 'disabled'];
        }
        $token = $this->telegramToken();
        $chat = $this->telegramChatId();
        if ($token === '' || $chat === '') {
            return ['sent' => 0, 'held' => 0, 'skipped' => 'telegram is not configured'];
        }

        $owner = $this->repository->ownerId();
        if ($owner === null) {
            return ['sent' => 0, 'held' => 0, 'skipped' => 'no owner'];
        }

        $matches = $this->matches((int) $owner, $now->subDays((int) $this->settings->get('follow_notifications.lookback_days')));
        if ($matches === []) {
            return ['sent' => 0, 'held' => 0, 'skipped' => null];
        }
        if ($this->quiet($now)) {
            return ['sent' => 0, 'held' => count($matches), 'skipped' => 'quiet hours'];
        }

        $cap = (int) $this->settings->get('follow_notifications.daily_cap');
        $sentToday = $this->repository->sentToday($now->startOfDay()->utc());
        $sent = 0;
        $held = 0;
        foreach ($matches as $match) {
            if ($sentToday + $sent >= $cap) {
                $held++;
                continue;
            }
            if ($this->send($token, $chat, $match)) {
                $this->repository->recordSent((int) $owner, $match);
                $sent++;
            }
        }

        return ['sent' => $sent, 'held' => $held, 'skipped' => null];
    }

    /**
     * New movies (first seen after $since) that match a follow and were not announced yet, newest first, with the reason.
     *
     * @return list<array{code: string, title: string, cover: string|null, reason: string}>
     */
    public function matches(int $userId, CarbonImmutable $since): array
    {
        $follows = $this->repository->follows($userId);
        if ($follows === []) {
            return [];
        }
        $performers = array_values(array_map(static fn(array $row): int => (int) $row['key'], array_filter($follows, static fn(array $row): bool => $row['subject'] === 'performer')));
        $makers = array_values(array_map(static fn(array $row): string => $row['key'], array_filter($follows, static fn(array $row): bool => $row['subject'] === 'maker')));
        $series = array_values(array_map(static fn(array $row): string => $row['key'], array_filter($follows, static fn(array $row): bool => $row['subject'] === 'series')));

        $rows = $this->repository->candidateMovies($userId, $since, $performers, $makers, $series);
        $names = $this->repository->performerNames($performers);
        $out = [];
        foreach ($rows as $row) {
            $reasons = [];
            if ($row['maker'] !== null && in_array($row['maker'], $makers, true)) {
                $reasons[] = 'maker ' . $row['maker'];
            }
            if ($row['series'] !== null && in_array($row['series'], $series, true)) {
                $reasons[] = 'series ' . $row['series'];
            }
            if ($performers !== []) {
                foreach ($this->repository->matchingPerformers($row['id'], $performers) as $pid) {
                    $reasons[] = 'performer ' . ($names[$pid] ?? '#' . $pid);
                }
            }
            $out[] = ['code' => $row['display_code'], 'title' => (string) ($row['title_en'] ?? $row['title_jp'] ?? $row['display_code']), 'cover' => $row['cover_url'], 'reason' => implode(', ', $reasons)];
        }

        return $out;
    }

    private function quiet(CarbonImmutable $now): bool
    {
        $from = (int) $this->settings->get('follow_notifications.quiet_from_hour');
        $to = (int) $this->settings->get('follow_notifications.quiet_to_hour');
        if ($from === $to) {
            return false;
        }
        $hour = (int) $now->format('G');

        return $from < $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }

    private function telegramToken(): string
    {
        $token = config('laravel-notifications.channels.telegram.bot_token', '');

        return is_string($token) ? $token : '';
    }

    private function telegramChatId(): string
    {
        $chatId = config('laravel-notifications.channels.telegram.chat_id', '');

        return is_string($chatId) ? $chatId : '';
    }

    /** @param array{code: string, title: string, cover: string|null, reason: string} $match */
    private function send(string $token, string $chat, array $match): bool
    {
        $base = rtrim((string) $this->settings->get('telegram.api_base'), '/') ?: 'https://api.telegram.org';
        $timeout = max(1, (int) $this->settings->get('telegram.timeout'));
        $url = rtrim((string) config('app.url'), '/') . '/app#/movie/' . rawurlencode($match['code']);
        $rendered = ($this->renderer ?? app(NotificationRenderer::class))->render('follow.match', [
            'movie_code' => $match['code'],
            'movie_title' => $match['title'],
            'follow_reason' => $match['reason'],
        ]);
        $caption = $rendered['title'] . "\n\n" . $rendered['body'];
        $buttons = [['text' => 'Open', 'url' => $url]];
        if ($this->downloads->enabled()) {
            $buttons[] = ['text' => 'Download', 'callback_data' => 'dl:' . $match['code']];
        }
        $keyboard = ['inline_keyboard' => [$buttons]];

        try {
            if (is_string($match['cover']) && $match['cover'] !== '') {
                $response = Http::timeout($timeout)->asJson()->post("{$base}/bot{$token}/sendPhoto", ['chat_id' => $chat, 'photo' => $match['cover'], 'caption' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard]);
                if ($response->successful()) {
                    ($this->renderer ?? app(NotificationRenderer::class))->markSent('follow.match');

                    return true;
                }
            }
            $response = Http::timeout($timeout)->asJson()->post("{$base}/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $caption, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard]);

            if ($response->successful()) {
                ($this->renderer ?? app(NotificationRenderer::class))->markSent('follow.match');
            }

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
