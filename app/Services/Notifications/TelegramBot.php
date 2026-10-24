<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Http\Controllers\SuggestController;
use App\Services\Crawl\CrawlStatusService;
use App\Services\Downloads\DownloadService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The owner's Telegram bot: ask for movies and performers, read the status, follow and list follows. It reads updates with
 * short polling (every minute from the scheduler), so no inbound port is opened, and it answers only the owner's chat id.
 * Every switch is a setting in the database. Download commands are not part of it until downloads exist.
 */
final class TelegramBot
{
    private const OFFSET_KIND = 'tg_offset';

    public function __construct(private readonly SettingsService $settings, private readonly CrawlStatusService $status, private readonly DownloadService $downloads) {}

    /** @return array{handled: int, ignored: int, skipped: string|null} */
    public function poll(): array
    {
        if (! (bool) $this->settings->get('telegram.bot_enabled')) {
            return ['handled' => 0, 'ignored' => 0, 'skipped' => 'disabled'];
        }
        $token = (string) $this->settings->get('telegram.bot_token');
        if ($token === '' || (string) $this->settings->get('telegram.chat_id') === '') {
            return ['handled' => 0, 'ignored' => 0, 'skipped' => 'telegram is not configured'];
        }

        $offset = (int) DB::table('user_marks')->where(['user_id' => 0, 'kind' => self::OFFSET_KIND])->value('key');
        try {
            $response = Http::timeout(max(1, (int) $this->settings->get('telegram.timeout')) + 5)->get($this->url($token, 'getUpdates'), ['offset' => $offset + 1, 'timeout' => 0, 'allowed_updates' => json_encode(['message', 'callback_query'])]);
        } catch (Throwable) {
            return ['handled' => 0, 'ignored' => 0, 'skipped' => 'telegram unreachable'];
        }
        if (! $response->successful()) {
            return ['handled' => 0, 'ignored' => 0, 'skipped' => 'telegram refused'];
        }

        $handled = $ignored = 0;
        $last = $offset;
        foreach ((array) $response->json('result', []) as $update) {
            $last = max($last, (int) ($update['update_id'] ?? 0));
            if ($this->handle((array) $update)) {
                $handled++;
            } else {
                $ignored++;
            }
        }
        if ($last > $offset) {
            DB::table('user_marks')->updateOrInsert(['user_id' => 0, 'kind' => self::OFFSET_KIND, 'subject' => 'telegram'], ['key' => (string) $last, 'created_at' => now()]);
        }

        return ['handled' => $handled, 'ignored' => $ignored, 'skipped' => null];
    }

    /** Answer one update; false when it is not from the owner's chat or has no text. */
    public function handle(array $update): bool
    {
        if (isset($update['callback_query'])) {
            return $this->callback((array) $update['callback_query']);
        }
        $message = (array) ($update['message'] ?? []);
        $chat = (string) ($message['chat']['id'] ?? '');
        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '' || $chat === '' || $chat !== (string) $this->settings->get('telegram.chat_id')) {
            return false;
        }

        [$reply, $buttons] = $this->answer($text);
        $this->send($chat, $reply, $buttons);

        return true;
    }

    /** The Download button of a notification: "dl:CODE". Only the owner's chat counts. */
    private function callback(array $query): bool
    {
        $chat = (string) ($query['message']['chat']['id'] ?? '');
        $data = (string) ($query['data'] ?? '');
        if ($chat === '' || $chat !== (string) $this->settings->get('telegram.chat_id') || ! str_starts_with($data, 'dl:')) {
            return false;
        }
        $reply = $this->download(substr($data, 3));
        try {
            Http::timeout(max(1, (int) $this->settings->get('telegram.timeout')))->asJson()->post($this->url((string) $this->settings->get('telegram.bot_token'), 'answerCallbackQuery'), ['callback_query_id' => (string) ($query['id'] ?? ''), 'text' => $reply]);
        } catch (Throwable) {
            // The text answer below still reaches the owner.
        }
        $this->send($chat, $reply, []);

        return true;
    }

    private function download(string $code): string
    {
        if (! $this->downloads->enabled()) {
            return 'Tải về đang tắt trong Cài đặt.';
        }
        try {
            $added = $this->downloads->add(trim($code));

            return 'Đã thêm ' . $added['movie_code'] . ' vào danh sách tải.';
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }
    }

    private function downloadsText(): string
    {
        if (! $this->downloads->enabled()) {
            return 'Tải về đang tắt trong Cài đặt.';
        }
        try {
            $items = $this->downloads->list();
        } catch (\RuntimeException $e) {
            return $e->getMessage();
        }

        return $items === [] ? 'Chưa có mục nào đang tải.' : implode("\n", array_map(static fn(array $t): string => ($t['movie_code'] ?? $t['name']) . ' · ' . $t['progress'] . '% · ' . $t['state'], array_slice($items, 0, 15)));
    }

    /** @return array{0: string, 1: list<array{text: string, url: string}>} */
    private function answer(string $text): array
    {
        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower(preg_replace('/@.*$/', '', $parts[0]) ?? '');
        $argument = trim($parts[1] ?? '');

        return match ($command) {
            '/start', '/help' => [$this->help(), []],
            '/status' => [$this->statusText(), []],
            '/download' => [$this->download($argument), []],
            '/downloads' => [$this->downloadsText(), []],
            '/movie' => $this->movie($argument),
            '/follow', '/unfollow' => [$this->follow($command === '/follow', $argument), []],
            '/follows' => [$this->follows(), []],
            '/search' => $this->search($argument),
            default => str_starts_with($text, '/') ? ["Lệnh không có. Gõ /help.", []] : $this->search($text),
        };
    }

    private function help(): string
    {
        return "Gõ tên diễn viên, mã phim, thể loại hoặc từ khoá để tìm.\n/search <từ khoá>\n/movie <mã phim>\n/status – số liệu hiện tại\n/download <mã phim> – tải về (khi bật)\n/downloads – các mục đang tải\n/follow maker|series|performer <tên> – theo dõi\n/unfollow maker|series|performer <tên>\n/follows – danh sách đang theo dõi";
    }

    private function statusText(): string
    {
        $c = $this->status->counts();
        $q = (array) ($this->status->status()['queue']['by_status'] ?? []);

        return sprintf("Phim: %s\nDiễn viên: %s\nHàng đợi: %s chờ, %s lỗi", number_format($c['movies']), number_format($c['performers']), number_format((int) ($q['pending'] ?? 0)), number_format((int) ($q['failed'] ?? 0)));
    }

    /** @return array{0: string, 1: list<array{text: string, url: string}>} */
    private function search(string $q): array
    {
        if (mb_strlen($q) < 2) {
            return ['Gõ ít nhất 2 ký tự để tìm.', []];
        }
        $limit = max(1, min(10, (int) $this->settings->get('telegram.bot_max_results')));
        $data = app(SuggestController::class)->index(Request::create('/', 'GET', ['q' => $q, 'limit' => $limit]))->getData(true)['data'] ?? [];

        $lines = [];
        $buttons = [];
        foreach ((array) ($data['movies'] ?? []) as $m) {
            $lines[] = '🎬 ' . $m['code'] . ' · ' . ($m['title'] ?? '') . (($m['maker'] ?? null) ? ' (' . $m['maker'] . ')' : '');
            if (count($buttons) < 3) {
                $buttons[] = ['text' => $m['code'], 'url' => $this->appUrl('/movie/' . rawurlencode($m['code']))];
            }
        }
        foreach ((array) ($data['performers'] ?? []) as $p) {
            $lines[] = '👤 ' . ($p['name'] ?? '#' . $p['id']);
        }
        foreach (['genres' => '🏷', 'makers' => '🏢', 'series' => '📁'] as $key => $icon) {
            foreach ((array) ($data[$key] ?? []) as $x) {
                $lines[] = $icon . ' ' . $x['name'] . ' (' . $x['movie_count'] . ' phim)';
            }
        }

        return [$lines === [] ? 'Không có kết quả cho “' . $q . '”.' : implode("\n", $lines), $buttons];
    }

    /** @return array{0: string, 1: list<array{text: string, url: string}>} */
    private function movie(string $code): array
    {
        if ($code === '') {
            return ['Gõ /movie <mã phim>, ví dụ /movie SSIS-001.', []];
        }
        $row = DB::table('movies')->whereRaw('UPPER(display_code) = ?', [strtoupper($code)])->first(['display_code', 'title_en', 'title_jp', 'maker', 'series', 'release_date', 'runtime_minutes', 'community_score']);
        if ($row === null) {
            return ['Chưa có phim ' . $code . '.', []];
        }
        $text = $row->display_code . ' · ' . ($row->title_en ?? $row->title_jp ?? '') . "\nPhát hành: " . ($row->release_date ? substr((string) $row->release_date, 0, 10) : 'chưa rõ')
            . "\nMaker: " . ($row->maker ?? 'chưa rõ') . ($row->series ? "\nSeries: " . $row->series : '') . ($row->runtime_minutes ? "\nThời lượng: {$row->runtime_minutes} phút" : '') . ($row->community_score !== null ? "\nĐiểm: {$row->community_score}" : '');

        return [$text, [['text' => 'Mở', 'url' => $this->appUrl('/movie/' . rawurlencode((string) $row->display_code))]]];
    }

    private function follow(bool $on, string $argument): string
    {
        $parts = preg_split('/\s+/', $argument, 2) ?: [];
        $subject = strtolower($parts[0] ?? '');
        $name = trim($parts[1] ?? '');
        if (! in_array($subject, ['maker', 'series', 'performer'], true) || $name === '') {
            return 'Cú pháp: /' . ($on ? 'follow' : 'unfollow') . ' maker|series|performer <tên>.';
        }
        $owner = DB::table('users')->where('role', 'super_administrator')->orderBy('id')->value('id');
        if ($owner === null) {
            return 'Chưa có tài khoản chủ sở hữu.';
        }
        $key = $name;
        if ($subject === 'performer') {
            $id = DB::table('performers')->whereRaw('LOWER(name_romaji) = ?', [mb_strtolower($name)])->orWhereRaw('LOWER(name_kanji) = ?', [mb_strtolower($name)])->value('id');
            if ($id === null) {
                return 'Không tìm thấy diễn viên “' . $name . '”.';
            }
            $key = (string) $id;
        }
        $row = ['user_id' => $owner, 'kind' => 'follow', 'subject' => $subject, 'key' => $key];
        if ($on) {
            DB::table('user_marks')->insertOrIgnore([...$row, 'created_at' => now()]);
        } else {
            DB::table('user_marks')->where($row)->delete();
        }

        return ($on ? 'Đang theo dõi ' : 'Đã bỏ theo dõi ') . $subject . ' ' . $name . '.';
    }

    private function follows(): string
    {
        $owner = DB::table('users')->where('role', 'super_administrator')->orderBy('id')->value('id');
        $rows = DB::table('user_marks')->where('user_id', $owner)->where('kind', 'follow')->orderBy('subject')->get(['subject', 'key']);
        if ($rows->isEmpty()) {
            return 'Chưa theo dõi gì. Dùng /follow.';
        }
        $names = DB::table('performers')->whereIn('id', $rows->where('subject', 'performer')->pluck('key')->map(static fn($k): int => (int) $k)->all())->pluck(DB::raw('COALESCE(name_romaji, name_kanji)'), 'id');

        return $rows->map(static fn($r): string => $r->subject . ' · ' . ($r->subject === 'performer' ? ($names[(int) $r->key] ?? '#' . $r->key) : $r->key))->implode("\n");
    }

    private function appUrl(string $path): string
    {
        return rtrim((string) config('app.url'), '/') . '/app#' . $path;
    }

    private function url(string $token, string $method): string
    {
        $base = rtrim((string) $this->settings->get('telegram.api_base'), '/') ?: 'https://api.telegram.org';

        return "{$base}/bot{$token}/{$method}";
    }

    /** @param list<array{text: string, url: string}> $buttons */
    private function send(string $chat, string $text, array $buttons): void
    {
        $payload = ['chat_id' => $chat, 'text' => mb_substr($text, 0, 4000)];
        if ($buttons !== []) {
            $payload['reply_markup'] = ['inline_keyboard' => [$buttons]];
        }
        try {
            Http::timeout(max(1, (int) $this->settings->get('telegram.timeout')))->asJson()->post($this->url((string) $this->settings->get('telegram.bot_token'), 'sendMessage'), $payload);
        } catch (Throwable) {
            // The next poll answers again only for new updates; a lost reply is not retried.
        }
    }
}
