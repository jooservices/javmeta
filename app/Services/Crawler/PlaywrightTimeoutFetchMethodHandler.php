<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use JOOservices\CrawlerX\Contracts\FetchMethodHandler;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\FetchResultDto;
use JOOservices\CrawlerX\Dto\SiteProfileDto;
use JOOservices\CrawlerX\Enums\FetchMethod;

final class PlaywrightTimeoutFetchMethodHandler implements FetchMethodHandler
{
    public function __construct(private readonly FetchMethodHandler $handler) {}

    public function supports(FetchMethod $method): bool
    {
        return $this->handler->supports($method);
    }

    public function fetch(
        string $url,
        SiteProfileDto $profile,
        FetchMethod $method,
        ?CrawlOptionsDto $options = null,
    ): FetchResultDto {
        $timeout = max(1, (int) config('javmeta_queues.playwright_timeout_seconds', 25));
        $requestedTimeout = $options?->methodTimeoutSeconds;
        if ($requestedTimeout !== null) {
            $timeout = min($timeout, max(1, $requestedTimeout));
        }

        return $this->handler->fetch($url, $profile, $method, new CrawlOptionsDto(
            http: $options?->http,
            fetch: $options?->fetch,
            methodTimeoutSeconds: $timeout,
            storageState: $options?->storageState,
            readyMarkers: $options === null ? [] : $options->readyMarkers,
        ));
    }
}
