<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use Closure;
use Illuminate\Support\Facades\Log;
use JOOservices\CrawlerX\Contracts\LoginCookieProvider;
use Throwable;

/**
 * crawlerx login cookies from SourceLoginCookieStore. crawlerx asks on every
 * fetch, so answers are kept in-process for a short time; a store outage
 * means "no cookies" (the crawl goes on anonymously). Values are never logged.
 * The store is resolved on first use so booting the app never touches it.
 */
final class LaravelConfigLoginCookieProvider implements LoginCookieProvider
{
    private const TTL_SECONDS = 60;

    /** @var array<string, array{expires: int, cookies: array<string, string>}> */
    private array $memo = [];

    /** @param Closure(): SourceLoginCookieStore $store */
    public function __construct(private readonly Closure $store) {}

    /** @return array<string, string> */
    public function cookiesFor(string $site): array
    {
        $now = time();
        if (isset($this->memo[$site]) && $this->memo[$site]['expires'] > $now) {
            return $this->memo[$site]['cookies'];
        }

        try {
            $cookies = ($this->store)()->cookies($site);
        } catch (Throwable $exception) {
            Log::warning('Source login cookies unavailable.', [
                'source' => $site,
                'exception' => $exception::class,
            ]);
            $cookies = [];
        }

        $this->memo[$site] = ['expires' => $now + self::TTL_SECONDS, 'cookies' => $cookies];

        return $cookies;
    }
}
