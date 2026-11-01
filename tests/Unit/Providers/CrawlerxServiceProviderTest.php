<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Events\SettingsApplied;
use App\Services\Crawler\LaravelConfigLoginCookieProvider;
use JOOservices\CrawlerX\CrawlerXFactory;
use Illuminate\Support\Facades\Event;
use ReflectionClass;
use ReflectionProperty;
use Tests\TestCase;

final class CrawlerxServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PLAYWRIGHT_URL');
        CrawlerXFactory::reset();
        parent::tearDown();
    }

    public function test_boot_wires_login_cookies_and_keeps_sessions_in_the_worker(): void
    {
        self::assertInstanceOf(LaravelConfigLoginCookieProvider::class, $this->factoryProperty('loginCookieProvider'));

        $factory = new ReflectionClass(CrawlerXFactory::class);

        if (! $factory->hasProperty('sessionCache')) {
            return;
        }

        self::assertNull($factory->getProperty('sessionCache')->getValue());
    }

    public function test_database_url_change_rebuilds_the_crawler_fetch_chain(): void
    {
        CrawlerXFactory::reset();
        putenv('PLAYWRIGHT_URL=http://old-service:3000');
        $before = CrawlerXFactory::create();
        config(['javmeta_browser.playwright_url' => 'http://new-service:3000']);

        Event::dispatch(new SettingsApplied(['browser.playwright_url']));

        self::assertSame('http://new-service:3000', getenv('PLAYWRIGHT_URL'));
        self::assertNotSame($before, CrawlerXFactory::create());
    }

    private function factoryProperty(string $name): mixed
    {
        return (new ReflectionProperty(CrawlerXFactory::class, $name))->getValue();
    }
}
