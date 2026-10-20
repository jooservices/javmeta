<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Services\Crawler\CrawlerxClient;
use App\Services\Crawler\CrawlerxFetchResult;
use Closure;

/**
 * Live check of one source: for every kind of page the source declares, fetch one public example and give a verdict.
 * WORKING = fetched and the required fields are there; DEGRADED = fetched but unusable data; BLOCKED = could not fetch.
 * At most two requests per capability (the listing and one item taken from it), never faster than the delay.
 */
final class SourceHealthChecker
{
    public const WORKING = 'WORKING';

    public const DEGRADED = 'DEGRADED';

    public const BLOCKED = 'BLOCKED';

    public const DISABLED = 'DISABLED';

    private const DEGRADED_CODES = [
        CrawlerxClient::ERROR_PARSE_FAILED,
        CrawlerxClient::ERROR_SOFT404,
    ];

    /** @param Closure(int): void $sleeper */
    public function __construct(private readonly CrawlerxClient $client, private readonly Closure $sleeper) {}

    /**
     * @param  array<string, mixed>  $config  the source entry of config/javmeta_sources.php
     * @return list<array{source: string, capability: string, url: string, verdict: string, error_code: ?string, reason: ?string}>
     */
    public function check(string $slug, array $config, bool $enabled, int $delaySeconds): array
    {
        if (! $enabled) {
            return [$this->row($slug, 'all', '', self::DISABLED, null, 'Source is switched off in JavMeta.')];
        }

        $rows = [];
        $first = true;
        $pause = function () use (&$first, $delaySeconds): void {
            if (! $first) {
                ($this->sleeper)($delaySeconds);
            }
            $first = false;
        };

        $listingUrl = $this->firstUrl($config, ['movie_listing_urls', 'listing_urls']);
        if ($listingUrl !== null) {
            $pause();
            $listing = $this->client->fetchListing($slug, $listingUrl);
            $rows[] = $this->fromResult($slug, 'listing', $listingUrl, $listing, fn() => $listing->list !== null && $listing->list->items !== []);
            $itemUrl = $listing->ok ? $this->firstTerminalItem($listing) : null;
            if ($itemUrl !== null) {
                $pause();
                $detail = $this->client->fetchDetail($slug, $itemUrl);
                $rows[] = $this->fromResult($slug, 'detail', $itemUrl, $detail, static fn() => $detail->movie !== null && trim((string) $detail->movie->code) !== '' && trim((string) $detail->movie->title) !== '');
            }
        }

        $performerListingUrl = $this->firstUrl($config, ['performer_listing_urls']);
        if ($performerListingUrl !== null) {
            $pause();
            $listing = $this->client->fetchPerformerListing($slug, $performerListingUrl);
            $rows[] = $this->fromResult($slug, 'performer_listing', $performerListingUrl, $listing, fn() => $listing->list !== null && $listing->list->items !== []);
            $itemUrl = $listing->ok ? $this->firstTerminalItem($listing) : null;
            if ($itemUrl !== null) {
                $pause();
                $performer = $this->client->fetchPerformerDetail($slug, $itemUrl);
                $rows[] = $this->fromResult($slug, 'performer_detail', $itemUrl, $performer, static fn() => $performer->performer !== null);
            }
        }

        $galleryUrl = $this->firstUrl($config, ['gallery_urls']);
        if ($galleryUrl !== null) {
            $pause();
            $gallery = $this->client->fetchGallery($slug, $galleryUrl);
            $rows[] = $this->fromResult($slug, 'gallery', $galleryUrl, $gallery, static fn() => $gallery->gallery !== null);
        }

        return $rows === [] ? [$this->row($slug, 'all', '', self::DEGRADED, null, 'The source declares no page to check.')] : $rows;
    }

    /**
     * @param  Closure(): bool  $hasData
     * @return array{source: string, capability: string, url: string, verdict: string, error_code: ?string, reason: ?string}
     */
    private function fromResult(string $slug, string $capability, string $url, CrawlerxFetchResult $result, Closure $hasData): array
    {
        if (! $result->ok) {
            $code = $result->errorCode ?? CrawlerxClient::ERROR_NETWORK;
            $verdict = in_array($code, self::DEGRADED_CODES, true) ? self::DEGRADED : self::BLOCKED;

            return $this->row($slug, $capability, $url, $verdict, $code, $result->errorMessage);
        }

        return $hasData()
            ? $this->row($slug, $capability, $url, self::WORKING, null, null)
            : $this->row($slug, $capability, $url, self::DEGRADED, null, 'Fetched, but the required data is missing.');
    }

    /** @param list<string> $keys */
    private function firstUrl(array $config, array $keys): ?string
    {
        foreach ($keys as $key) {
            $urls = $config[$key] ?? null;
            if (is_array($urls) && isset($urls[0]) && is_string($urls[0]) && $urls[0] !== '') {
                return $urls[0];
            }
        }

        return null;
    }

    private function firstTerminalItem(CrawlerxFetchResult $result): ?string
    {
        foreach ($result->list->items ?? [] as $item) {
            if ($item->nextCrawlType === null) {
                return $item->url;
            }
        }

        return null;
    }

    /** @return array{source: string, capability: string, url: string, verdict: string, error_code: ?string, reason: ?string} */
    private function row(string $slug, string $capability, string $url, string $verdict, ?string $code, ?string $reason): array
    {
        return ['source' => $slug, 'capability' => $capability, 'url' => $url, 'verdict' => $verdict, 'error_code' => $code, 'reason' => $reason];
    }
}
