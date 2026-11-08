<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(SettingsServiceProvider::class)]
final class SettingsServiceProviderTest extends TestCase
{
    public function test_the_settings_service_is_one_shared_instance(): void
    {
        self::assertSame(app(SettingsService::class), app(SettingsService::class));
    }

    public function test_outside_the_test_suite_it_applies_settings_at_boot_before_jobs_and_before_commands(): void
    {
        config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        $events = $this->app->make(Dispatcher::class);
        $booted = [];
        $app = Mockery::mock($this->app)->makePartial();
        $app->shouldReceive('runningUnitTests')->andReturn(false);
        $app->shouldReceive('booted')->once()->andReturnUsing(static function (callable $callback) use (&$booted): void {
            $booted[] = $callback;
        });

        (new SettingsServiceProvider($app))->boot();

        self::assertCount(1, $booted, 'Settings are applied once the application has booted.');
        self::assertTrue($events->hasListeners(JobProcessing::class), 'A queue job applies fresh settings first.');
        self::assertTrue($events->hasListeners(CommandStarting::class), 'A console command applies fresh settings first.');
    }

    public function test_without_an_application_key_nothing_is_wired(): void
    {
        config(['app.key' => '']);
        $app = Mockery::mock($this->app)->makePartial();
        $app->shouldReceive('runningUnitTests')->andReturn(false);
        $app->shouldReceive('booted')->never();

        (new SettingsServiceProvider($app))->boot();

        $this->addToAssertionCount(1);
    }

    public function test_in_the_test_suite_boot_does_nothing(): void
    {
        $app = Mockery::mock($this->app)->makePartial();
        $app->shouldReceive('booted')->never();

        (new SettingsServiceProvider($app))->boot();

        self::assertInstanceOf(Application::class, $this->app);
    }
}
