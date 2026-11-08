<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\Notifications\RenderNotificationCommand;
use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/** Gap coverage for follows:notify, telegram:poll, notifications:list and notifications:render. */
final class NotificationCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsService::forgetDefaults();
        $this->app->instance(ConfigStore::class, new FakeConfigStore());
        $this->app->forgetInstance(SettingsService::class);
        config([
            'laravel-notifications.channels.telegram.bot_token' => '',
            'laravel-notifications.channels.telegram.chat_id' => '',
        ]);
    }

    protected function tearDown(): void
    {
        SettingsService::forgetDefaults();

        parent::tearDown();
    }

    public function test_follow_notify_reports_when_the_feature_is_disabled(): void
    {
        app(SettingsService::class)->set('follow_notifications.enabled', false);

        $this->artisan('follows:notify')
            ->expectsOutputToContain('Sent 0, held back 0 (disabled).')
            ->assertSuccessful();
    }

    public function test_follow_notify_reports_when_telegram_is_not_configured(): void
    {

        $this->artisan('follows:notify')
            ->expectsOutputToContain('Sent 0, held back 0 (telegram is not configured).')
            ->assertSuccessful();
    }

    public function test_follow_notify_without_matches_prints_a_plain_summary(): void
    {
        Http::fake();
        config([
            'laravel-notifications.channels.telegram.bot_token' => '123:token',
            'laravel-notifications.channels.telegram.chat_id' => '555',
        ]);
        User::factory()->create(['role' => 'super_administrator']);
        app(SettingsService::class)->set('follow_notifications.enabled', true);

        $this->artisan('follows:notify')
            ->expectsOutputToContain('Sent 0, held back 0.')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_telegram_poll_reports_when_the_bot_is_disabled(): void
    {
        app(SettingsService::class)->set('telegram.bot_enabled', false);

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('Answered 0, ignored 0 (disabled).')
            ->assertSuccessful();
    }

    public function test_telegram_poll_reports_when_telegram_is_not_configured(): void
    {
        app(SettingsService::class)->set('telegram.bot_enabled', true);

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('Answered 0, ignored 0 (telegram is not configured).')
            ->assertSuccessful();
    }

    public function test_telegram_poll_reports_when_telegram_refuses_the_request(): void
    {
        config([
            'laravel-notifications.channels.telegram.bot_token' => '123:token',
            'laravel-notifications.channels.telegram.chat_id' => '555',
        ]);
        $settings = app(SettingsService::class);
        $settings->set('telegram.api_base', 'https://api.telegram.test');
        $settings->set('telegram.bot_enabled', true);
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => false], 500)]);

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('Answered 0, ignored 0 (telegram refused).')
            ->assertSuccessful();
    }

    public function test_telegram_poll_without_updates_prints_a_plain_summary(): void
    {
        config([
            'laravel-notifications.channels.telegram.bot_token' => '123:token',
            'laravel-notifications.channels.telegram.chat_id' => '555',
        ]);
        $settings = app(SettingsService::class);
        $settings->set('telegram.api_base', 'https://api.telegram.test');
        $settings->set('telegram.bot_enabled', true);
        Http::fake(['api.telegram.test/*' => Http::response(['ok' => true, 'result' => []])]);

        $this->artisan('telegram:poll')
            ->expectsOutputToContain('Answered 0, ignored 0.')
            ->assertSuccessful();
    }

    public function test_notifications_list_prints_a_table_without_the_json_flag(): void
    {
        self::assertSame(0, Artisan::call('notifications:list'));

        $output = Artisan::output();
        self::assertStringContainsString('| Key', $output);
        self::assertStringContainsString('Last edited', $output);
        self::assertStringContainsString('crawl.success_rate_low', $output);
        self::assertStringNotContainsString('"key"', $output);
    }

    public function test_render_reports_a_framework_argument_error_as_a_failed_command(): void
    {
        // Dropping the --sample option from the definition makes option() raise InvalidArgumentException,
        // which the command must turn into an error line and a failure exit code.
        $command = $this->app->make(RenderNotificationCommand::class);
        $command->setLaravel($this->app);
        $command->getDefinition()->setOptions([]);
        $output = new BufferedOutput();

        $exitCode = $command->run(new ArrayInput(['key' => 'crawl.dispatch_delayed'], $command->getDefinition()), $output);

        self::assertSame(SymfonyCommand::FAILURE, $exitCode);
        self::assertStringContainsString('sample', $output->fetch());
    }
}
