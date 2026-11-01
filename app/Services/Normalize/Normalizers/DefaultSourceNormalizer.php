<?php

declare(strict_types=1);

namespace App\Services\Normalize\Normalizers;

use App\Data\Crawl\PerformerDraft;
use App\Data\Crawl\MovieDraft;
use App\Services\Normalize\NormalizationFailedException;
use App\Services\Normalize\SourceNormalizer;
use App\Support\Code\SiteCodeCanonicalizer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use JOOservices\CrawlerX\Dto\Entity\MovieDto;
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
use Throwable;

/**
 * Maps crawlerx MovieDto typed fields plus a configurable metadata{} key map
 * onto a MovieDraft. The key map is per-source configurable through
 * `javmeta_sources.sources.<slug>.normalize` and defaults to a union of the
 * key shapes observed across the frozen Wave 1 adapters.
 */
class DefaultSourceNormalizer implements SourceNormalizer
{
    public function supports(string $slug): bool
    {
        return is_array(config("javmeta_sources.sources.{$slug}"));
    }

    public function normalizeMovie(MovieDto $movie, array $metadata, string $slug, string $sourceUrl, CarbonImmutable $crawledAt): MovieDraft
    {
        $code = SiteCodeCanonicalizer::resolve($slug, $this->codeCandidates($movie, $metadata, $sourceUrl), $sourceUrl);
        $title = $this->titleFrom($movie, $metadata);

        if ($code === null || $title === null) {
            throw new NormalizationFailedException(sprintf(
                'Missing required movie fields for source [%s] (code: %s, title: %s).',
                $slug,
                $code === null ? 'missing' : 'present',
                $title === null ? 'missing' : 'present',
            ));
        }

        $titles = $this->titlesFrom($title, $metadata, $slug);

        $performers = [];
        foreach ($movie->performers as $performer) {
            $draft = $this->performerFrom($performer, $slug);
            if ($draft !== null) {
                $performers[] = $draft;
            }
        }

        $duration = ($movie->duration ?? 0) >= 1 ? $movie->duration : null;

        try {
            return MovieDraft::from(
                sourceSlug: $slug,
                sourceUrl: $sourceUrl,
                code: $code,
                titleJp: $titles['jp'],
                titleEn: $titles['en'],
                description: (is_string($movie->description) && trim($movie->description) !== ''
                    ? trim($movie->description)
                    : $this->firstString($metadata, $this->keyMap($slug)['description'] ?? ['description', 'Description', 'あらすじ', '紹介'])),
                releaseDate: $this->releaseDateFrom($movie->date),
                runtimeMinutes: $duration,
                censored: null,
                maker: $this->firstString($metadata, $this->keyMap($slug)['maker'] ?? []),
                label: $this->firstString($metadata, $this->keyMap($slug)['label'] ?? []),
                series: $this->firstString($metadata, $this->keyMap($slug)['series'] ?? []),
                genres: $this->genresFrom($movie, $metadata, $slug),
                performers: $performers,
                directors: $this->directorsFrom($metadata, $slug),
                coverUrl: $movie->coverUrl,
                extras: [
                    'magnets' => $this->magnetsFrom($metadata, $slug),
                    'hls' => $this->hlsFrom($metadata, $slug),
                    'gallery' => $this->galleryFrom($movie),
                    'score' => $this->scoreFrom($metadata, $slug),
                ],
                crawledAt: $crawledAt,
            );
        } catch (InvalidArgumentException $exception) {
            throw new NormalizationFailedException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * The page title lands in the locale the source serves. When CrawlerX also
     * returns both languages (`titles` {ja,en}) or the original-language title
     * (`original_title`), the other column is filled too.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{jp: ?string, en: ?string}
     */
    protected function titlesFrom(string $title, array $metadata, string $slug): array
    {
        $titles = ['jp' => null, 'en' => null];
        $locale = $this->titleLocale($slug);
        if ($locale === 'auto') {
            $locale = preg_match('/[\x{3040}-\x{30ff}\x{3400}-\x{9fff}]/u', $title) === 1 ? 'jp' : 'en';
        }
        $titles[$locale === 'en' ? 'en' : 'jp'] = $title;

        $both = $metadata['titles'] ?? null;
        if (is_array($both)) {
            foreach (['ja' => 'jp', 'en' => 'en'] as $from => $to) {
                if (is_string($both[$from] ?? null) && trim($both[$from]) !== '') {
                    $titles[$to] = trim($both[$from]);
                }
            }
        }

        $original = $metadata['original_title'] ?? null;
        if ($titles['jp'] === null && is_string($original) && trim($original) !== '') {
            $titles['jp'] = trim($original);
        }

        return $titles;
    }

    /**
     * Ordered candidates: typed code, metadata labels, title, external id, URL.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<string|null>
     */
    protected function codeCandidates(MovieDto $movie, array $metadata, string $sourceUrl): array
    {
        return [
            $movie->code,
            $this->firstString($metadata, [
                'code',
                'product_code',
                'product_code_raw',
                'Product ID',
                '品番',
                'ID',
                'Maker Code',
                'XCITY Code',
                'maker_code',
                'xcity_code',
                'Serial Number',
                'Number',
            ]),
            $movie->title,
            $this->firstString($metadata, ['title']),
            $movie->externalId,
            $this->queryParam($sourceUrl, 'id'),
            $sourceUrl,
            $this->urlBasename($sourceUrl),
        ];
    }

    protected function queryParam(string $url, string $key): ?string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $value = $params[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : (is_numeric($value) ? (string) $value : null);
    }

    protected function urlBasename(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $base = trim(basename($path), '/');
        if ($base === '' || str_contains($base, '.')) {
            $parent = trim(basename(dirname($path)), '/');

            return $parent !== '' && $parent !== '.' ? $parent : null;
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function titleFrom(MovieDto $movie, array $metadata): ?string
    {
        if ($movie->title !== null && trim($movie->title) !== '') {
            return trim($movie->title);
        }

        return $this->firstString($metadata, ['title']);
    }

    protected function titleLocale(string $slug): string
    {
        $locale = config("javmeta_sources.sources.{$slug}.normalize.title_locale");

        return is_string($locale) && $locale !== ''
            ? $locale
            : (string) config('javmeta_sources.defaults.normalize.title_locale', 'jp');
    }

    /**
     * @return array<string, list<string>>
     */
    protected function keyMap(string $slug): array
    {
        $default = config('javmeta_sources.defaults.normalize.key_map');
        $map = is_array($default) ? $default : [];

        $override = config("javmeta_sources.sources.{$slug}.normalize.key_map");
        if (is_array($override)) {
            $map = array_merge($map, $override);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    protected function genresFrom(MovieDto $movie, array $metadata, string $slug): array
    {
        $genres = [];
        foreach ($movie->tags as $tag) {
            $genres[$this->normalizeLabel($tag)] = $tag;
        }

        $raw = $this->firstString($metadata, $this->keyMap($slug)['genres'] ?? ['genres']);
        if ($raw !== null) {
            foreach (preg_split('/[,、|]/u', $raw) ?: [] as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $genres[$this->normalizeLabel($part)] = $part;
                }
            }
        }

        return array_values($genres);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    protected function magnetsFrom(array $metadata, string $slug): array
    {
        $keys = $this->keyMap($slug)['magnets'] ?? ['downloads', 'download_url'];
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return [['url' => trim($value)]];
            }

            if (! is_array($value)) {
                continue;
            }

            $entries = [];
            foreach ($value as $entry) {
                if (! is_array($entry) || ! is_string($entry['url'] ?? null) || trim($entry['url']) === '') {
                    continue;
                }
                $entry['url'] = trim($entry['url']);
                $entries[] = $entry;
            }
            if ($entries !== []) {
                $unique = [];
                foreach ($entries as $entry) {
                    $unique[$entry['url']] ??= $entry;
                }

                return array_values($unique);
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<array<string, mixed>>
     */
    protected function hlsFrom(array $metadata, string $slug): array
    {
        $keys = $this->keyMap($slug)['hls'] ?? ['stream', 'sample_streams', 'sample_video_url'];
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return [['url' => trim($value)]];
            }

            if (! is_array($value)) {
                continue;
            }

            $manifest = $value['manifest_url'] ?? null;
            if (is_string($manifest) && trim($manifest) !== '') {
                $entry = ['url' => trim($manifest)];
                if (is_string($value['video_id'] ?? null) && $value['video_id'] !== '') {
                    $entry['video_id'] = $value['video_id'];
                }

                return [$entry];
            }

            $entries = [];
            foreach ($value as $entry) {
                if (! is_array($entry) || ! is_string($entry['url'] ?? null) || trim($entry['url']) === '') {
                    continue;
                }
                $entry['url'] = trim($entry['url']);
                $entries[] = $entry;
            }
            if ($entries !== []) {
                return $entries;
            }
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function galleryFrom(MovieDto $movie): array
    {
        $gallery = [];
        foreach ($movie->screenshots as $screenshot) {
            $entry = ['url' => $screenshot->url];
            if ($screenshot->thumbnailUrl !== null && $screenshot->thumbnailUrl !== '') {
                $entry['thumbnail_url'] = $screenshot->thumbnailUrl;
            }
            $gallery[] = $entry;
        }

        return $gallery;
    }

    /**
     * CrawlerX returns `rating` as {score, scale, votes}; a bare number is
     * taken as already on the 0-10 scale. The stored score is always 0-10.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function scoreFrom(array $metadata, string $slug): ?float
    {
        $keys = $this->keyMap($slug)['score'] ?? ['rating', 'Rating', 'score', '評価'];
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_array($value)) {
                $score = $value['score'] ?? null;
                $scale = $value['scale'] ?? null;
                if (! is_numeric($score)) {
                    continue;
                }

                $scale = is_numeric($scale) && (float) $scale > 0 ? (float) $scale : 10.0;

                $normalized = $this->clampScore((float) $score / $scale * 10.0);
                if ($normalized !== null) {
                    return $normalized;
                }

                continue;
            }

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                $normalized = $this->clampScore((float) $value);
                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    private function clampScore(float $score): ?float
    {
        return $score < 0.0 || $score > 10.0 ? null : round($score, 2);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<string>
     */
    protected function directorsFrom(array $metadata, string $slug): array
    {
        $keys = $this->keyMap($slug)['directors'] ?? ['director', 'Director', 'directors', '監督'];
        $names = [];

        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $names[] = trim($value);
                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            foreach ($value as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    $names[] = trim($entry);
                } elseif (is_array($entry) && is_string($entry['name'] ?? null) && trim($entry['name']) !== '') {
                    $names[] = trim($entry['name']);
                }
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  list<string>  $keys
     */
    protected function firstString(array $metadata, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    protected function releaseDateFrom(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($date));
        } catch (Throwable) {
            return null;
        }
    }

    protected function performerFrom(PerformerDto $performer, string $slug): ?PerformerDraft
    {
        $externalId = $performer->externalId ?? $performer->name;
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        return new PerformerDraft(
            sourceSlug: $slug,
            externalId: trim($externalId),
            nameRomaji: $performer->name,
            nameKanji: $performer->nameJapanese,
            nameKana: null,
            aliases: array_values(array_filter(
                $performer->aliases,
                static fn(string $alias): bool => trim($alias) !== '',
            )),
            profileUrl: $performer->url,
            imageUrl: $performer->profileImageUrl,
        );
    }

    protected function normalizeLabel(string $label): string
    {
        return mb_strtolower(trim($label));
    }
}
