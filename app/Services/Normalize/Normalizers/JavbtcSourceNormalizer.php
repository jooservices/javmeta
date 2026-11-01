<?php

declare(strict_types=1);

namespace App\Services\Normalize\Normalizers;

use App\Data\Crawl\MovieDraft;
use App\Services\Normalize\JavbtcCodeMissingException;
use App\Support\Code\SiteCodeCanonicalizer;
use Carbon\CarbonImmutable;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;

/** Normalizes JavBTC details and distinguishes pages that contain no usable code. */
final class JavbtcSourceNormalizer extends DefaultSourceNormalizer
{
    public function supports(string $slug): bool
    {
        return $slug === 'javbtc';
    }

    public function normalizeMovie(MovieDto $movie, array $metadata, string $slug, string $sourceUrl, CarbonImmutable $crawledAt): MovieDraft
    {
        if (SiteCodeCanonicalizer::resolve($slug, $this->codeCandidates($movie, $metadata, $sourceUrl, $slug), $sourceUrl) === null) {
            throw new JavbtcCodeMissingException('JavBTC detail page has no recognizable product code.');
        }

        return parent::normalizeMovie($movie, $metadata, $slug, $sourceUrl, $crawledAt);
    }
}
