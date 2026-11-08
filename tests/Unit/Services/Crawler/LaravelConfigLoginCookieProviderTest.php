<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\LaravelConfigLoginCookieProvider;
use App\Services\Crawler\SourceLoginCookieStore;
use Illuminate\Support\Facades\Log;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use JOOservices\LaravelConfig\Testing\FakeConfigStore;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class LaravelConfigLoginCookieProviderTest extends TestCase
{
    public function test_returns_stored_cookies_for_the_site(): void
    {
        $store = new SourceLoginCookieStore(new FakeConfigStore());
        $cookies = ['sid' => fake()->sha256()];
        $store->put('javdb', $cookies);

        $provider = new LaravelConfigLoginCookieProvider(static fn(): SourceLoginCookieStore => $store);

        self::assertSame($cookies, $provider->cookiesFor('javdb'));
        self::assertSame([], $provider->cookiesFor('onejav'));
    }

    public function test_store_outage_returns_no_cookies_and_logs_no_value(): void
    {
        $secret = fake()->sha256();
        $config = Mockery::mock(ConfigStore::class);
        $config->shouldReceive('fresh')->once()->andThrow(new RuntimeException($secret));
        $logged = [];
        Log::listen(static function ($event) use (&$logged): void {
            $logged[] = $event->message . json_encode($event->context);
        });

        $provider = new LaravelConfigLoginCookieProvider(static fn(): SourceLoginCookieStore => new SourceLoginCookieStore($config));

        self::assertSame([], $provider->cookiesFor('javdb'));
        self::assertSame([], $provider->cookiesFor('javdb'), 'outage answer is memoized, no second store call');
        self::assertNotEmpty($logged);
        foreach ($logged as $line) {
            self::assertStringNotContainsString($secret, $line);
        }
    }
}
