<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one Telegram message for each new movie of a followed performer, maker or series, with the cover, the title, the
 * reason and an Open button. Quiet hours hold messages back until they end; a daily cap stops a flood. Every switch and
 * limit is a setting in the database (group "follow notifications"). The bot sends only; nothing opens an inbound port.
 */
final class FollowNotifier
{
    public function __construct(private readonly SettingsService $settings) {}

    /** @return array{sent: int, held: int, skipped: string|null} */
    public function run(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now((string) config('javmeta_alerts.hourly_report.timezone', 'Asia/Ho_Chi_Minh'));
        if (! (bool) $this->settings->get('follow_notifications.enabled')) {
            return ['sent' => 0, 'held' => 0, 'skipped' => 'disabled'];
        }
        $token = (string) $this->settings->get('telegram.bot_token');
        $chat = (string) $this->settings->get('telegram.chat_id');
        if ($token === '' || $chat === '') {
            return ['sent' => 0, 'held' => 0, 'skipped' => 'telegram is not configured'];
        }

        $owner = DB::table('users')->where('role', 'super_administrator')->orderBy('id')->value('id');
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
        $sentToday = (int) DB::table('follow_notifications')->where('channel', 'telegram')->where('sent_at', '>=', $now->startOfDay()->utc())->count();
        $sent = 0;
        $held = 0;
        foreach ($matches as $match) {
            if ($sentToday + $sent >= $cap) {
                $held++;
                continue;
            }
            if ($this->send($token, $chat, $match)) {
                DB::table('follow_notifications')->insertOrIgnore(['user_id' => $owner, 'movie_code' => $match['code'], 'reason' => $match['reason'], 'channel' => 'telegram', 'sent_at' => now()]);
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
        $follows = DB::table('user_marks')->where('user_id', $userId)->where('kind', 'follow')->get(['subject', 'key']);
        if ($follows->isEmpty()) {
            return [];
        }
        $performers = $follows->where('subject', 'performer')->pluck('key')->map(static fn($k): int => (int) $k)->all();
        $makers = $follows->where('subject', 'maker')->pluck('key')->all();
        $series = $follows->where('subject', 'series')->pluck('key')->all();

        $rows = DB::table('movies')->where('first_seen_at', '>=', $since->utc())->whereNull('delisted_at')
            ->whereNotIn('display_code', DB::table('follow_notifications')->where('user_id', $userId)->where('channel', 'telegram')->select('movie_code'))
            ->where(function ($w) use ($performers, $makers, $series): void {
                if ($performers !== []) {
                    $w->orWhereIn('id', DB::table('movie_performers')->whereIn('performer_id', $performers)->select('movie_id'));
                }
                if ($makers !== []) {
                    $w->orWhereIn('maker', $makers);
                }
                if ($series !== []) {
                    $w->orWhereIn('series', $series);
                }
            })
            ->orderByDesc('first_seen_at')->limit(200)->get(['id', 'display_code', 'title_en', 'title_jp', 'cover_url', 'maker', 'series']);

        $names = $performers === [] ? collect() : DB::table('performers')->whereIn('id', $performers)->pluck(DB::raw('COALESCE(name_romaji, name_kanji)'), 'id');
        $out = [];
        foreach ($rows as $row) {
            $reasons = [];
            if ($row->maker !== null && in_array($row->maker, $makers, true)) {
                $reasons[] = 'maker ' . $row->maker;
            }
            if ($row->series !== null && in_array($row->series, $series, true)) {
                $reasons[] = 'series ' . $row->series;
            }
            if ($performers !== []) {
                foreach (DB::table('movie_performers')->where('movie_id', $row->id)->whereIn('performer_id', $performers)->pluck('performer_id') as $pid) {
                    $reasons[] = 'diễn viên ' . ($names[$pid] ?? '#' . $pid);
                }
            }
            $out[] = ['code' => (string) $row->display_code, 'title' => (string) ($row->title_en ?? $row->title_jp ?? $row->display_code), 'cover' => $row->cover_url, 'reason' => implode(', ', $reasons)];
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

    /** @param array{code: string, title: string, cover: string|null, reason: string} $match */
    private function send(string $token, string $chat, array $match): bool
    {
        $base = rtrim((string) $this->settings->get('telegram.api_base'), '/') ?: 'https://api.telegram.org';
        $timeout = max(1, (int) $this->settings->get('telegram.timeout'));
        $url = rtrim((string) config('app.url'), '/') . '/app#/movie/' . rawurlencode($match['code']);
        $caption = $match['code'] . ' · ' . $match['title'] . "\n" . 'Vì theo dõi: ' . $match['reason'];
        $keyboard = ['inline_keyboard' => [[['text' => 'Mở', 'url' => $url]]]];

        try {
            if (is_string($match['cover']) && $match['cover'] !== '') {
                $response = Http::timeout($timeout)->asJson()->post("{$base}/bot{$token}/sendPhoto", ['chat_id' => $chat, 'photo' => $match['cover'], 'caption' => $caption, 'reply_markup' => $keyboard]);
                if ($response->successful()) {
                    return true;
                }
            }
            $response = Http::timeout($timeout)->asJson()->post("{$base}/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $caption, 'reply_markup' => $keyboard]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
