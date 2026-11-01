<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\SettingsApplied;
use App\Support\BrowserServiceEnvironment;
use App\Services\Crawler\LaravelConfigLoginCookieProvider;
use App\Services\Crawler\SourceLoginCookieStore;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use JOOservices\CrawlerX\CrawlerXFactory;

/**
 * Wires the login cookies kept in laravel-config.
 *
 * Fetch sessions stay in the worker process. This provider does not pass a cache.
 */
final class CrawlerxServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureCrawler();
        Event::listen(SettingsApplied::class, function (SettingsApplied $event): void {
            $changed = false;
            foreach ($event->keys as $key) {
                $configKey = match ($key) {
                    'browser.flaresolverr_url' => 'javmeta_browser.flaresolverr_url',
                    'browser.playwright_url' => 'javmeta_browser.playwright_url',
                    default => null,
                };
                if ($configKey !== null) {
                    $changed = BrowserServiceEnvironment::apply($key, (string) config($configKey, '')) || $changed;
                }
            }

            if ($changed) {
                $this->configureCrawler();
            }
        });
    }

    private function configureCrawler(): void
    {
        CrawlerXFactory::configure(
            logins: new LaravelConfigLoginCookieProvider(fn(): SourceLoginCookieStore => $this->app->make(SourceLoginCookieStore::class)),
        );
    }
}
