<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\CrawlerxFetchChainFactory;
use App\Services\Crawler\RetryProxyHttpFetchHandler;
use JOOservices\CrawlerX\Enums\FetchMethod;
use PHPUnit\Framework\Attributes\CoversClass;
use ReflectionProperty;
use Tests\TestCase;

#[CoversClass(CrawlerxFetchChainFactory::class)]
final class CrawlerxFetchChainFactoryTest extends TestCase
{
    public function test_retry_profile_overrides_browser_endpoints_and_http_egress(): void
    {
        $chain = app(CrawlerxFetchChainFactory::class)->create(null, profile: [
            'flaresolverr_url' => 'http://retry-flare.internal:8191',
            'playwright_url' => 'http://retry-playwright.internal:3000',
            'http_proxy_url' => 'http://retry-proxy.internal:8080',
        ]);
        $runtime = (new ReflectionProperty($chain, 'runtime'))->getValue($chain);
        $handlers = (new ReflectionProperty($chain, 'handlers'))->getValue($chain);

        self::assertSame('http://retry-flare.internal:8191', $runtime->flaresolverrUrl);
        self::assertSame('http://retry-playwright.internal:3000', $runtime->playwrightUrl);
        self::assertInstanceOf(RetryProxyHttpFetchHandler::class, $handlers[FetchMethod::Http->value]);
    }
}
