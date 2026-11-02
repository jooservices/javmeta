<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\PlaywrightTimeoutFetchMethodHandler;
use JOOservices\CrawlerX\Contracts\FetchMethodHandler;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\FetchResultDto;
use JOOservices\CrawlerX\Dto\SiteProfileDto;
use JOOservices\CrawlerX\Enums\FetchMethod;
use JOOservices\CrawlerX\Registry\FileAdapterManifestRegistry;
use Tests\TestCase;

final class PlaywrightTimeoutFetchMethodHandlerTest extends TestCase
{
    public function test_playwright_process_receives_the_database_configured_timeout(): void
    {
        config()->set('javmeta_queues.playwright_timeout_seconds', 25);
        $profile = SiteProfileDto::fromManifest(app(FileAdapterManifestRegistry::class)->get('javbus'));
        $result = new FetchResultDto(
            ok: false,
            body: '',
            status: 0,
            methodUsed: FetchMethod::Playwright,
            elapsedMs: 25_000,
            challengeDetected: false,
            finalUrl: 'https://www.javbus.com/abc',
        );
        $delegate = \Mockery::mock(FetchMethodHandler::class);
        $delegate->shouldReceive('fetch')
            ->withArgs(static function (string $url, SiteProfileDto $site, FetchMethod $method, ?CrawlOptionsDto $options): bool {
                return $method === FetchMethod::Playwright && $options?->methodTimeoutSeconds === 25;
            })
            ->once()
            ->andReturn($result);
        $handler = new PlaywrightTimeoutFetchMethodHandler($delegate);

        self::assertSame($result, $handler->fetch(
            'https://www.javbus.com/abc',
            $profile,
            FetchMethod::Playwright,
            new CrawlOptionsDto(methodTimeoutSeconds: 45),
        ));
    }
}
