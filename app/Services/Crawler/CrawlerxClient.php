<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use JOOservices\CrawlerX\CrawlerX;
use JOOservices\CrawlerX\Dto\CrawlOptionsDto;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
use JOOservices\CrawlerX\Dto\FetchOptionsDto;
use JOOservices\CrawlerX\Enums\CrawlErrorCode;
use JOOservices\CrawlerX\Enums\CrawlType;
use App\Observability\ObservabilityEmitter;

/**
 * Thin adapter over the CrawlerX public API. crawlerx owns every fetch
 * decision; javmeta only passes a time budget (job timeout minus a margin),
 * routes the request explicitly by site and crawl type, and normalizes every
 * outcome into a CrawlerxFetchResult. Never writes to the database. Not final
 * so tests can substitute a fixture-backed fake.
 */
class CrawlerxClient
{
    public const ERROR_BLOCKED = 'blocked';

    public const ERROR_CHALLENGE = 'challenge';

    public const ERROR_PARSE_FAILED = 'parse_failed';

    public const ERROR_UNSUPPORTED_URL = 'unsupported_url';

    public const ERROR_AMBIGUOUS_URL = 'ambiguous_url';

    public const ERROR_ADAPTER_NOT_FOUND = 'adapter_not_found';

    public const ERROR_SOFT404 = 'soft404';

    public const ERROR_NOT_FOUND = 'not_found';

    public const ERROR_GONE = 'gone';

    public const ERROR_RATE_LIMITED = 'rate_limited';

    public const ERROR_TIMEOUT = 'timeout';

    public const ERROR_NETWORK = 'network';

    public const ERROR_AUTH_REQUIRED = 'auth_required';

    public const ERROR_SSRF_BLOCKED = 'ssrf_blocked';

    public const ERROR_UNKNOWN = 'unknown';

    /** Seconds kept free between the crawlerx deadline and the worker job timeout. */
    private const DEADLINE_MARGIN_SECONDS = 30;

    public function fetchListing(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        return $this->fetch($sourceSlug, $url, CrawlType::Listing);
    }

    public function fetchDetail(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        return $this->fetch($sourceSlug, $url, CrawlType::Detail);
    }

    public function fetchPerformerListing(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        return $this->fetch($sourceSlug, $url, CrawlType::PerformerListing);
    }

    public function fetchPerformerDetail(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        return $this->fetch($sourceSlug, $url, CrawlType::PerformerDetail);
    }

    public function fetchGallery(string $sourceSlug, string $url): CrawlerxFetchResult
    {
        return $this->fetch($sourceSlug, $url, CrawlType::Gallery);
    }

    private function fetch(string $sourceSlug, string $url, CrawlType $type): CrawlerxFetchResult
    {
        $started = hrtime(true);
        $result = $this->doFetch($sourceSlug, $url, $type);
        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        app(ObservabilityEmitter::class)->emitFetch(
            $sourceSlug,
            $type->value,
            $result->ok,
            $durationMs,
            $result->errorCode,
            $result->attempts,
            [
                'url' => $url,
                'error_message' => $result->errorMessage,
                'retryable' => $result->retryable,
                'retry_after_seconds' => $result->retryAfterSeconds,
                'crawlerx_version' => class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('jooservices/crawlerx')
                    ? \Composer\InstalledVersions::getPrettyVersion('jooservices/crawlerx')
                    : null,
            ],
        );

        return $result;
    }

    private function doFetch(string $sourceSlug, string $url, CrawlType $type): CrawlerxFetchResult
    {
        $outcome = CrawlerX::url($url)
            ->site($sourceSlug)
            ->type($type)
            ->options($this->options())
            ->tryCrawl();

        if ($outcome->failed()) {
            $error = $outcome->error;

            return CrawlerxFetchResult::failure(
                $this->errorCode($error->code, $error->fetch?->challengeDetected),
                $error->message,
                $error->retryable,
                $error->retryAfterSeconds,
                $error->fetch->attempts ?? [],
            );
        }

        if ($outcome->list !== null) {
            if (! in_array($type, [CrawlType::Listing, CrawlType::PerformerListing], true)) {
                return CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Item crawl returned a listing result.');
            }

            return CrawlerxFetchResult::success($outcome->list);
        }

        $item = $outcome->item;
        if ($item === null) {
            return CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Crawl returned neither a list nor an item.');
        }

        return match ($type) {
            CrawlType::Detail => $this->movieFromItem($item->entityType, $item->meta),
            CrawlType::PerformerDetail => $this->performerFromItem($item->url, $item->entityType, $item->meta),
            CrawlType::Gallery => $this->galleryFromItem($item->entityType, $item->meta),
            default => CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Listing crawl returned an item result.'),
        };
    }

    /** @param array<string, mixed> $meta */
    private function movieFromItem(string $entityType, array $meta): CrawlerxFetchResult
    {
        $movie = is_array($meta['movie'] ?? null) ? $meta['movie'] : null;
        if ($entityType !== 'movie' || $movie === null) {
            return CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Detail fetch did not return a movie result.');
        }

        return CrawlerxFetchResult::movie(MovieDto::fromParsed(null, null, $movie));
    }

    /** @param array<string, mixed> $meta */
    private function performerFromItem(string $url, string $entityType, array $meta): CrawlerxFetchResult
    {
        $payload = is_array($meta['performer'] ?? null) ? $meta['performer'] : null;
        if ($entityType !== 'performer' || $payload === null) {
            return CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Performer detail fetch did not return a performer result.');
        }

        $externalId = is_string($payload['external_id'] ?? null) ? $payload['external_id'] : null;
        $name = is_string($payload['name'] ?? null) ? $payload['name'] : null;

        return CrawlerxFetchResult::performer(PerformerDto::fromParsed($externalId, $name, $payload, $url));
    }

    /** @param array<string, mixed> $meta */
    private function galleryFromItem(string $entityType, array $meta): CrawlerxFetchResult
    {
        $payload = is_array($meta['gallery'] ?? null) ? $meta['gallery'] : null;
        if ($entityType !== 'gallery' || $payload === null) {
            return CrawlerxFetchResult::failure(self::ERROR_PARSE_FAILED, 'Gallery fetch did not return a gallery result.');
        }

        $externalId = is_string($payload['external_id'] ?? null) ? $payload['external_id'] : null;
        $title = is_string($payload['title'] ?? null) ? $payload['title'] : null;

        return CrawlerxFetchResult::gallery(GalleryDto::fromParsed($externalId, $title, $payload));
    }

    private function options(): CrawlOptionsDto
    {
        $deadline = (int) config('javmeta_queues.worker_timeout', 180) - self::DEADLINE_MARGIN_SECONDS;

        return new CrawlOptionsDto(fetch: new FetchOptionsDto(deadlineSeconds: max(1, $deadline)));
    }

    private function errorCode(?CrawlErrorCode $code, ?bool $challengeDetected): string
    {
        if ($code === CrawlErrorCode::Blocked && $challengeDetected === true) {
            return self::ERROR_CHALLENGE;
        }

        return match ($code) {
            CrawlErrorCode::Blocked => self::ERROR_BLOCKED,
            CrawlErrorCode::UnsupportedUrl => self::ERROR_UNSUPPORTED_URL,
            CrawlErrorCode::AmbiguousUrl => self::ERROR_AMBIGUOUS_URL,
            CrawlErrorCode::AdapterNotFound => self::ERROR_ADAPTER_NOT_FOUND,
            CrawlErrorCode::ParseFailed => self::ERROR_PARSE_FAILED,
            CrawlErrorCode::NotFound => self::ERROR_NOT_FOUND,
            CrawlErrorCode::Gone => self::ERROR_GONE,
            CrawlErrorCode::RateLimited => self::ERROR_RATE_LIMITED,
            CrawlErrorCode::Timeout => self::ERROR_TIMEOUT,
            CrawlErrorCode::Challenge => self::ERROR_CHALLENGE,
            CrawlErrorCode::Network => self::ERROR_NETWORK,
            CrawlErrorCode::AuthRequired => self::ERROR_AUTH_REQUIRED,
            CrawlErrorCode::SsrfBlocked => self::ERROR_SSRF_BLOCKED,
            CrawlErrorCode::Unknown, null => self::ERROR_UNKNOWN,
        };
    }
}
