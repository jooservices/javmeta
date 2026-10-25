<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the settings stored in the database to the Laravel config: when the app boots, before each queue job and before
 * each console command, so a value saved in the UI reaches long-lived workers and the scheduler without a deploy.
 */
final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Tests set the config they need; they never talk to the real store.
        if ($this->app->runningUnitTests()) {
            return;
        }

        // Build steps such as package:discover boot the app before any key exists; the encrypted store cannot be built then.
        if (empty(config('app.key'))) {
            return;
        }

        $this->app->booted(static fn() => app(SettingsService::class)->apply());
        Event::listen(JobProcessing::class, static fn() => app(SettingsService::class)->apply());
        Event::listen(CommandStarting::class, static fn() => app(SettingsService::class)->apply());
    }
}
