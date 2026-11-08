<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use JOOservices\Client\Client\ClientBuilder;
use JOOservices\CrawlerX\Contracts\FetchMethodHandler;
use JOOservices\CrawlerX\Contracts\LoginCookieProvider;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\FetchResultDto;
use JOOservices\CrawlerX\Dto\SiteProfileDto;
use JOOservices\CrawlerX\Enums\FetchMethod;
use JOOservices\CrawlerX\Fetch\ChallengeDetector;
use JOOservices\CrawlerX\Fetch\Guard\TransientRetry;
use JOOservices\CrawlerX\Fetch\Session\CookieHandoffStore;
use JOOservices\CrawlerX\Fetch\Session\SessionStore;
use Nyholm\Psr7\Request;
use Throwable;

final class RetryProxyHttpFetchHandler implements FetchMethodHandler
{
    public function __construct(
        private readonly string $proxyUrl,
        private readonly CookieHandoffStore $cookies,
        private readonly SessionStore $sessions,
        private readonly ?LoginCookieProvider $logins = null,
    ) {}

    public function supports(FetchMethod $method): bool
    {
        return $method === FetchMethod::Http;
    }

    public function fetch(string $url, SiteProfileDto $profile, FetchMethod $method, ?CrawlOptionsDto $options = null): FetchResultDto
    {
        $started = (int) round(microtime(true) * 1000);
        $headers = $profile->http->headers;
        $cookie = $this->cookies->cookieHeader($url);
        if ($cookie !== null) {
            $headers['Cookie'] = $cookie;
        }
        $loginCookies = $this->logins?->cookiesFor($profile->slug) ?? [];
        $sessionCookie = $this->sessions->cookieHeader($profile->slug, $loginCookies);
        if ($sessionCookie !== null) {
            $headers['Cookie'] = $sessionCookie;
        }
        $timeout = $profile->http->timeout;
        if ($options !== null) {
            $timeout = $options->methodTimeoutSeconds ?? $options->http->timeout ?? $timeout;
        }
        $builder = ClientBuilder::create()->withTimeout((float) $timeout)->withProxy($this->proxyUrl);
        $builder = $builder->withVerifySsl($profile->http->verifySsl);

        try {
            $response = $builder->build()->sendRequest(new Request('GET', $url, $headers));
            $body = (string) $response->getBody();
            $status = $response->getStatusCode();
            $headerMap = [];
            foreach ($response->getHeaders() as $name => $values) {
                $headerMap[(string) $name] = array_values($values);
            }
        } catch (Throwable $exception) {
            return new FetchResultDto(
                ok: false,
                body: '',
                status: 0,
                methodUsed: $method,
                elapsedMs: (int) round(microtime(true) * 1000) - $started,
                challengeDetected: false,
                finalUrl: $url,
                error: TransientRetry::errorCode($exception),
            );
        }

        $challenge = ChallengeDetector::isChallenge($body, $status, $headerMap);
        $ok = ! $challenge && ChallengeDetector::isUsableBody($body, $status);

        return new FetchResultDto(
            ok: $ok,
            body: $body,
            status: $status,
            methodUsed: $method,
            elapsedMs: (int) round(microtime(true) * 1000) - $started,
            challengeDetected: $challenge,
            finalUrl: $url,
            headers: $headerMap,
            error: $ok ? null : ($challenge ? 'challenge page' : 'unusable HTTP body'),
        );
    }
}
