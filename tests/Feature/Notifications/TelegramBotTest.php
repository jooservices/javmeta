<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use App\Models\Movie;
use App\Models\User;
use App\Services\Notifications\TelegramBot;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config(['app.url' => 'https://jav.example.test']);
        User::factory()->create(['role' => 'super_administrator']);
        $s = app(SettingsService::class);
        $s->set('telegram.bot_token', '123:token');
        $s->set('telegram.chat_id', '555');
        $s->set('telegram.api_base', 'https://api.telegram.test');
        $s->set('telegram.bot_enabled', true);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    private function say(string $text, string $chat = '555'): array
    {
        Http::swap(new Factory());
        Http::fake([
            'api.telegram.test/*getUpdates*' => Http::response(['ok' => true, 'result' => [['update_id' => max(10, 1 + (int) DB::table('user_marks')->where('kind', 'tg_offset')->value('key')), 'message' => ['chat' => ['id' => (int) $chat], 'text' => $text]]]]),
            'api.telegram.test/*sendMessage*' => Http::response(['ok' => true]),
        ]);

        return app(TelegramBot::class)->poll();
    }

    public function test_it_answers_a_search_with_movies_and_open_buttons(): void
    {
        Movie::factory()->create(['display_code' => 'BOT-001', 'title_en' => 'Findable story', 'maker' => 'Botmaker']);

        $result = $this->say('findable');

        $this->assertSame(1, $result['handled']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && $r['chat_id'] === '555' && str_contains($r['text'], 'BOT-001')
            && $r['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://jav.example.test/app#/movie/BOT-001');
        $this->assertSame('10', DB::table('user_marks')->where('kind', 'tg_offset')->value('key'), 'the offset is stored so an update is answered once');
    }

    public function test_only_the_owner_chat_is_answered(): void
    {
        $result = $this->say('hello there', '999');

        $this->assertSame(['handled' => 0, 'ignored' => 1, 'skipped' => null], $result);
        Http::assertNotSent(fn($r) => str_contains($r->url(), '/sendMessage'));
    }

    public function test_commands_movie_status_follow_and_follows(): void
    {
        Movie::factory()->create(['display_code' => 'CMD-001', 'title_en' => 'Command movie', 'maker' => 'Cmdmaker']);

        $this->say('/movie cmd-001');
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'CMD-001') && str_contains($r['text'], 'Cmdmaker'));

        $this->say('/follow maker Cmdmaker');
        $this->assertTrue(DB::table('user_marks')->where(['kind' => 'follow', 'subject' => 'maker', 'key' => 'Cmdmaker'])->exists());
        $this->say('/follows');
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'maker · Cmdmaker'));

        $this->say('/unfollow maker Cmdmaker');
        $this->assertFalse(DB::table('user_marks')->where(['kind' => 'follow'])->exists());

        $this->say('/status');
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'Phim:'));
        $this->say('/nonsense');
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], '/help'));
    }

    public function test_it_does_nothing_when_switched_off(): void
    {
        app(SettingsService::class)->set('telegram.bot_enabled', false);
        Http::fake();

        $this->assertSame('disabled', app(TelegramBot::class)->poll()['skipped']);
        Http::assertNothingSent();
    }

    public function test_poison_updates_do_not_block_following_updates(): void
    {
        foreach ([str_repeat('a', 101), '/search ' . str_repeat('a', 101), '/movie ' . str_repeat('a', 101), '/follow maker ' . str_repeat('a', 101)] as $index => $text) {
            Http::swap(new Factory());
            $first = 20 + $index * 2;
            Http::fake([
                '*getUpdates*' => Http::response(['ok' => true, 'result' => [
                    ['update_id' => $first, 'message' => ['chat' => ['id' => 555], 'text' => $text]],
                    ['update_id' => $first + 1, 'message' => ['chat' => ['id' => 555], 'text' => '/help']],
                ]]),
                '*sendMessage*' => Http::response(['ok' => true]),
            ]);
            $this->assertSame(2, app(TelegramBot::class)->poll()['handled']);
            Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], '100 ký tự'));
            $this->assertSame((string) ($first + 1), DB::table('user_marks')->where('kind', 'tg_offset')->value('key'));
        }
    }

    public function test_send_failure_preserves_progress_and_restart_retries_only_the_failed_update(): void
    {
        $updates = [
            ['update_id' => 30, 'message' => ['chat' => ['id' => 555], 'text' => '/help']],
            ['update_id' => 31, 'message' => ['chat' => ['id' => 555], 'text' => '/help']],
        ];
        Http::fake([
            '*getUpdates*' => Http::response(['ok' => true, 'result' => $updates]),
            '*sendMessage*' => Http::sequence()->push(['ok' => true])->push(['ok' => false], 200),
        ]);
        $this->assertSame(1, app(TelegramBot::class)->poll()['handled']);
        $this->assertSame('30', DB::table('user_marks')->where('kind', 'tg_offset')->value('key'));
        Http::swap(new Factory());
        Http::fake([
            '*getUpdates*' => Http::response(['ok' => true, 'result' => $updates]),
            '*sendMessage*' => Http::response(['ok' => true]),
        ]);
        $this->app->forgetInstance(TelegramBot::class);
        $this->assertSame(1, app(TelegramBot::class)->poll()['handled']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/getUpdates') && $r['offset'] === 31);
        Http::assertSentCount(2);
        $this->assertSame('31', DB::table('user_marks')->where('kind', 'tg_offset')->value('key'));
    }

    public function test_transport_failure_never_acknowledges_the_failing_update_or_logs_the_token(): void
    {
        Log::spy();
        Http::fake([
            '*getUpdates*' => Http::response(['ok' => true, 'result' => [
                ['update_id' => 50, 'message' => ['chat' => ['id' => 555], 'text' => '/help']],
                ['update_id' => 51, 'message' => ['chat' => ['id' => 555], 'text' => '/help']],
            ]]),
            '*sendMessage*' => Http::failedConnection(),
        ]);
        $this->assertSame(['handled' => 0, 'ignored' => 0, 'skipped' => 'telegram update failed'], app(TelegramBot::class)->poll());
        $this->assertDatabaseMissing('user_marks', ['kind' => 'tg_offset']);
        Http::assertSentCount(2);
        Log::shouldHaveReceived('warning')->once()->with('javmeta.telegram_update_failed', [
            'update_id' => 50, 'exception' => ConnectionException::class,
        ]);
    }
}
