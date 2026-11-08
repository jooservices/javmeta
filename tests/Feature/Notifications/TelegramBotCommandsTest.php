<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Movie;
use App\Models\Performer;
use App\Models\User;
use App\Services\Notifications\TelegramBot;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(TelegramBot::class)]
final class TelegramBotCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config(['app.url' => 'https://jav.example.test']);
        config(['laravel-notifications.channels.telegram.bot_token' => '123:token', 'laravel-notifications.channels.telegram.chat_id' => '555']);
        User::factory()->create(['role' => 'super_administrator']);
        $settings = app(SettingsService::class);
        $settings->set('telegram.api_base', 'https://api.telegram.test');
        $settings->set('telegram.bot_enabled', true);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    /** @return string the text of the last message the bot sent */
    private function reply(string $text): string
    {
        $sent = [];
        Http::swap(new Factory());
        Http::fake([
            'api.telegram.test/*getUpdates*' => Http::response(['ok' => true, 'result' => [['update_id' => 10 + (int) DB::table('user_marks')->where('kind', 'tg_offset')->value('key'), 'message' => ['chat' => ['id' => 555], 'text' => $text]]]]),
            'api.telegram.test/*sendMessage*' => static function ($request) use (&$sent) {
                $sent[] = $request['text'];

                return Http::response(['ok' => true]);
            },
        ]);

        app(TelegramBot::class)->poll();

        return (string) end($sent);
    }

    public function test_polling_without_a_bot_token_or_chat_is_skipped(): void
    {
        config(['laravel-notifications.channels.telegram.bot_token' => '', 'javmeta_alerts.telegram.bot_token' => '']);

        self::assertSame(['handled' => 0, 'ignored' => 0, 'skipped' => 'telegram is not configured'], app(TelegramBot::class)->poll());
    }

    public function test_an_unreachable_or_refusing_telegram_is_reported_not_raised(): void
    {
        Http::swap(new Factory());
        Http::fake(static fn() => throw new ConnectionException('refused'));
        self::assertSame('telegram unreachable', app(TelegramBot::class)->poll()['skipped']);

        Http::swap(new Factory());
        Http::fake(['*' => Http::response('', 500)]);
        self::assertSame('telegram refused', app(TelegramBot::class)->poll()['skipped']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function replies(): iterable
    {
        yield 'help' => ['/help', '/follows – danh sách đang theo dõi'];
        yield 'start with the bot name' => ['/start@xcrawler_bot', 'Gõ tên diễn viên'];
        yield 'unknown command' => ['/nonsense', 'Lệnh không có. Gõ /help.'];
        yield 'search needs two characters' => ['/search a', 'Gõ ít nhất 2 ký tự để tìm.'];
        yield 'search with no result' => ['/search zzzzqqq', 'Không có kết quả cho “zzzzqqq”.'];
        yield 'movie without a code' => ['/movie', 'Gõ /movie <mã phim>, ví dụ /movie SSIS-001.'];
        yield 'unknown movie' => ['/movie NOPE-999', 'Chưa có phim NOPE-999.'];
        yield 'follow with a bad subject' => ['/follow color red', 'Cú pháp: /follow maker|series|performer <tên>.'];
        yield 'unfollow with no name' => ['/unfollow maker', 'Cú pháp: /unfollow maker|series|performer <tên>.'];
        yield 'follow an unknown performer' => ['/follow performer Nobody Here', 'Không tìm thấy diễn viên “Nobody Here”.'];
        yield 'follows when empty' => ['/follows', 'Chưa theo dõi gì. Dùng /follow.'];
        yield 'search too long' => ['/search ' . 'x' . '' . self::LONG, 'Câu hỏi quá dài, tối đa 100 ký tự.'];
        yield 'argument too long' => ['/movie ' . self::LONG, 'Tham số quá dài, tối đa 100 ký tự.'];
        yield 'plain text too long' => [self::LONG, 'Câu hỏi quá dài, tối đa 100 ký tự.'];
        yield 'downloads switched off' => ['/downloads', 'Tải về đang tắt trong Cài đặt.'];
        yield 'download switched off' => ['/download SSIS-001', 'Tải về đang tắt trong Cài đặt.'];
    }

    private const LONG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[DataProvider('replies')]
    public function test_each_command_answers_in_the_owners_language(string $text, string $expectedFragment): void
    {
        self::assertStringContainsString($expectedFragment, $this->reply($text));
    }

    public function test_search_lists_movies_performers_and_catalogue_groups(): void
    {
        Movie::factory()->create(['display_code' => 'BOT-777', 'title_en' => 'Zebra story', 'maker' => 'Zebramaker']);
        Performer::factory()->create(['name_romaji' => 'Zebra Performer']);

        $text = $this->reply('/search zebra');

        self::assertStringContainsString('🎬 BOT-777 · Zebra story (Zebramaker)', $text);
        self::assertStringContainsString('👤 Zebra Performer', $text);
    }

    public function test_a_known_movie_is_described_with_release_runtime_and_score(): void
    {
        Movie::factory()->create(['display_code' => 'CMD-777', 'title_en' => 'Described', 'maker' => 'Cmdmaker', 'series' => 'Cmdseries', 'release_date' => '2024-05-06', 'runtime_minutes' => 120, 'community_score' => 8.5]);

        $text = $this->reply('/movie cmd-777');

        self::assertStringContainsString('CMD-777 · Described', $text);
        self::assertStringContainsString('Phát hành: 2024-05-06', $text);
        self::assertStringContainsString('Series: Cmdseries', $text);
        self::assertStringContainsString('Thời lượng: 120 phút', $text);
        self::assertStringContainsString('Điểm: 8.5', $text);
    }

    public function test_following_a_performer_by_name_is_stored_by_id_and_listed_by_name(): void
    {
        $performer = Performer::factory()->create(['name_romaji' => 'Follow Me']);

        self::assertSame('Đang theo dõi performer follow me.', $this->reply('/follow performer follow me'));
        self::assertTrue(DB::table('user_marks')->where(['kind' => 'follow', 'subject' => 'performer', 'key' => (string) $performer->id])->exists());
        self::assertStringContainsString('performer · Follow Me', $this->reply('/follows'));
        self::assertSame('Đã bỏ theo dõi performer follow me.', $this->reply('/unfollow performer follow me'));
        self::assertFalse(DB::table('user_marks')->where('kind', 'follow')->exists());
    }

    public function test_following_needs_an_owner_account(): void
    {
        DB::table('users')->delete();

        self::assertSame('Chưa có tài khoản chủ sở hữu.', $this->reply('/follow maker Anything'));
    }

    public function test_the_status_command_reports_counts_and_queue(): void
    {
        Movie::factory()->count(2)->create();

        $text = $this->reply('/status');

        self::assertStringContainsString('Phim: 2', $text);
        self::assertStringContainsString('Hàng đợi: 0 chờ, 0 lỗi', $text);
    }
}
