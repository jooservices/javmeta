<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Movie;
use App\Models\User;
use App\Services\Notifications\FollowNotifier;
use App\Services\Settings\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Tests\TestCase;

final class FollowNotifierTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config(['app.url' => 'https://jav.example.test']);
        $this->owner = User::factory()->create(['role' => 'super_administrator']);
        $settings = app(SettingsService::class);
        $settings->set('telegram.bot_token', '123:token');
        $settings->set('telegram.chat_id', '555');
        $settings->set('telegram.api_base', 'https://api.telegram.test');
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => true])]);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();
        parent::tearDown();
    }

    private function follow(string $subject, string $key): void
    {
        DB::table('user_marks')->insert(['user_id' => $this->owner->id, 'kind' => 'follow', 'subject' => $subject, 'key' => $key, 'created_at' => now()]);
    }

    private function noon(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-24 12:00:00', 'Asia/Ho_Chi_Minh');
    }

    public function test_a_new_movie_of_a_followed_maker_is_sent_once_with_cover_reason_and_open_button(): void
    {
        $this->follow('maker', 'Followed');
        Movie::factory()->create(['display_code' => 'FOL-001', 'title_en' => 'A title', 'maker' => 'Followed', 'cover_url' => 'https://img.test/c.jpg', 'first_seen_at' => now()->subHour()]);
        Movie::factory()->create(['display_code' => 'OTH-001', 'maker' => 'Other', 'first_seen_at' => now()->subHour()]);

        $first = app(FollowNotifier::class)->run($this->noon());
        $this->assertSame(1, $first['sent']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/bot123:token/sendPhoto')
            && $r['chat_id'] === '555' && str_contains($r['caption'], 'FOL-001') && str_contains($r['caption'], 'maker Followed')
            && $r['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://jav.example.test/app#/movie/FOL-001');

        $second = app(FollowNotifier::class)->run($this->noon());
        $this->assertSame(0, $second['sent'], 'a movie is announced once');
        $this->assertSame(1, DB::table('follow_notifications')->count());
    }

    public function test_a_followed_performer_matches_and_a_missing_cover_falls_back_to_a_text_message(): void
    {
        $movie = Movie::factory()->create(['display_code' => 'PER-001', 'cover_url' => null, 'first_seen_at' => now()->subHour()]);
        $performerId = DB::table('performers')->insertGetId(['source_slug' => 's', 'external_id' => '1', 'name_romaji' => 'Aiko Test', 'uuid' => 'u-1', 'crawled_at' => now(), 'first_seen_at' => now(), 'updated_at' => now(), 'needs_review' => false, 'attrs' => '{}']);
        DB::table('movie_performers')->insert(['movie_id' => $movie->id, 'performer_id' => $performerId, 'source_slug' => 's', 'crawled_at' => now()]);
        $this->follow('performer', (string) $performerId);

        $this->assertSame(1, app(FollowNotifier::class)->run($this->noon())['sent']);
        Http::assertSent(fn($r) => str_contains($r->url(), '/sendMessage') && str_contains($r['text'], 'diễn viên Aiko Test'));
    }

    public function test_quiet_hours_hold_messages_back_and_the_daily_cap_limits_them(): void
    {
        $this->follow('maker', 'Followed');
        Movie::factory()->count(3)->create(['maker' => 'Followed', 'first_seen_at' => now()->subHour()]);
        $settings = app(SettingsService::class);

        $night = CarbonImmutable::parse('2026-10-24 02:00:00', 'Asia/Ho_Chi_Minh');
        $held = app(FollowNotifier::class)->run($night);
        $this->assertSame(['sent' => 0, 'held' => 3, 'skipped' => 'quiet hours'], $held);
        Http::assertNothingSent();

        $settings->set('follow_notifications.daily_cap', 2);
        $day = app(FollowNotifier::class)->run($this->noon());
        $this->assertSame(2, $day['sent']);
        $this->assertSame(1, $day['held']);
    }

    public function test_it_does_nothing_when_switched_off_or_telegram_is_not_set_up(): void
    {
        $this->follow('maker', 'Followed');
        Movie::factory()->create(['maker' => 'Followed', 'first_seen_at' => now()->subHour()]);
        $settings = app(SettingsService::class);

        $settings->set('follow_notifications.enabled', false);
        $this->assertSame('disabled', app(FollowNotifier::class)->run($this->noon())['skipped']);

        $settings->set('follow_notifications.enabled', true);
        $settings->set('telegram.bot_token', '');
        $this->assertSame('telegram is not configured', app(FollowNotifier::class)->run($this->noon())['skipped']);
        Http::assertNothingSent();
    }
}
