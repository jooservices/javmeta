<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use JOOservices\CrawlerX\Contracts\LoginCookieProvider;
use JOOservices\CrawlerX\Enums\FetchMethod;
use JOOservices\CrawlerX\Fetch\BrowserServiceProcessRunner;
use JOOservices\CrawlerX\Fetch\FetchFallbackChain;
use JOOservices\CrawlerX\Fetch\FetchRuntimeConfig;
use JOOservices\CrawlerX\Fetch\Guard\HostCircuit;
use JOOservices\CrawlerX\Fetch\Guard\HostThrottle;
use JOOservices\CrawlerX\Fetch\Handlers\CurlImpersonateFetchHandler;
use JOOservices\CrawlerX\Fetch\Handlers\FlaresolverrFetchHandler;
use JOOservices\CrawlerX\Fetch\Handlers\HttpFetchHandler;
use JOOservices\CrawlerX\Fetch\Handlers\PlaywrightFamilyFetchHandler;
use JOOservices\CrawlerX\Fetch\Handlers\PuppeteerStealthFetchHandler;
use JOOservices\CrawlerX\Fetch\ProcOpenProcessRunner;
use JOOservices\CrawlerX\Fetch\Session\CookieHandoffStore;
use JOOservices\CrawlerX\Fetch\Session\SessionStore;
use JOOservices\CrawlerX\Services\ClientFactory;
use Psr\SimpleCache\CacheInterface;

final class CrawlerxFetchChainFactory
{
    /** @param array<string, mixed> $profile */
    public function create(?LoginCookieProvider $logins, ?CacheInterface $throttleCache = null, array $profile = []): FetchFallbackChain
    {
        // Mirror the package's default handler map while injecting the database-backed Playwright timeout.
        $environment = FetchRuntimeConfig::fromEnvironment();
        $runtime = new FetchRuntimeConfig(
            nodeBinary: $environment->nodeBinary,
            playwrightScript: $environment->playwrightScript,
            puppeteerScript: $environment->puppeteerScript,
            curlImpersonateBinary: $environment->curlImpersonateBinary,
            flaresolverrUrl: is_string($profile['flaresolverr_url'] ?? null) && $profile['flaresolverr_url'] !== '' ? $profile['flaresolverr_url'] : $environment->flaresolverrUrl,
            playwrightUrl: is_string($profile['playwright_url'] ?? null) && $profile['playwright_url'] !== '' ? $profile['playwright_url'] : $environment->playwrightUrl,
            userAgent: $environment->userAgent,
            userAgentPool: $environment->userAgentPool,
            nodeId: $environment->nodeId,
        );
        $runner = new ProcOpenProcessRunner();
        $browserRunner = $runtime->playwrightUrl === null
            ? $runner
            : new BrowserServiceProcessRunner($runtime->playwrightUrl);
        $cookies = new CookieHandoffStore();
        $sessions = new SessionStore();
        $clientFactory = new ClientFactory();
        $playwright = new PlaywrightTimeoutFetchMethodHandler(new PlaywrightFamilyFetchHandler($runtime, $browserRunner));

        $httpHandler = is_string($profile['http_proxy_url'] ?? null) && $profile['http_proxy_url'] !== ''
            ? new RetryProxyHttpFetchHandler($profile['http_proxy_url'], $cookies, $sessions, $logins)
            : new HttpFetchHandler($clientFactory, $cookies, $sessions, $logins);

        return new FetchFallbackChain([
            FetchMethod::Http->value => $httpHandler,
            FetchMethod::CurlImpersonate->value => new CurlImpersonateFetchHandler($runtime, $runner, $cookies, $sessions, $logins),
            FetchMethod::Playwright->value => $playwright,
            FetchMethod::PlaywrightStealth->value => $playwright,
            FetchMethod::ChromeStealth->value => $playwright,
            FetchMethod::PuppeteerStealth->value => new PuppeteerStealthFetchHandler($runtime, $browserRunner),
            FetchMethod::Flaresolverr->value => new FlaresolverrFetchHandler($runtime, null, $sessions, $logins),
        ], $cookies, $sessions, $logins, $runtime, hostThrottle: new HostThrottle(
            cache: $throttleCache,
            nodeId: $runtime->nodeId,
        ), hostCircuit: new HostCircuit(
            cache: $throttleCache,
            nodeId: $runtime->nodeId,
        ));
    }
}
