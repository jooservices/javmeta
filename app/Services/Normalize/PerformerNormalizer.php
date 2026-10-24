<?php

declare(strict_types=1);

namespace App\Services\Normalize;

use App\Data\Crawl\PerformerDraft;
use JOOservices\CrawlerX\Dto\Entity\PerformerDto;
use Throwable;

/**
 * Maps crawlerx PerformerDto (+ raw metadata) onto PerformerDraft for profile crawl.
 */
final class PerformerNormalizer
{
    public function normalize(PerformerDto $performer, string $sourceSlug, string $url): ?PerformerDraft
    {
        $externalId = $this->cleanText($performer->externalId ?? $performer->name);
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        $externalId = trim($externalId);
        $meta = $performer->metadata;
        $sizeRaw = $this->cleanText($performer->sizeRaw ?? (is_string($meta['size_raw'] ?? null) ? $meta['size_raw'] : null));
        $measurements = $this->measurements($this->parseSize($sizeRaw), $meta);
        $tags = $this->cleanStringList($performer->tags);
        $bio = $this->bioText($performer, $meta);
        $attrs = array_filter([
            'tags' => $tags !== [] ? $tags : null,
            'zodiac_sign' => $this->cleanText(is_string($meta['zodiac_sign'] ?? null) ? $meta['zodiac_sign'] : null),
            'age' => is_int($meta['age'] ?? null) ? (string) $meta['age'] : (is_string($meta['age'] ?? null) ? $this->cleanText($meta['age']) : null),
            'extra' => $this->extraMetadata($meta),
            'shoe_size_raw' => $this->cleanText(is_string($meta['shoe_size_raw'] ?? null) ? $meta['shoe_size_raw'] : null),
            'hair_length' => $this->cleanText(is_string($meta['hair_length'] ?? null) ? $meta['hair_length'] : null),
            'hair_color' => $this->cleanText(is_string($meta['hair_color'] ?? null) ? $meta['hair_color'] : null),
            'favorite_count_raw' => $this->cleanText(is_string($meta['favorite_count_raw'] ?? null) ? $meta['favorite_count_raw'] : null),
        ], static fn(mixed $v): bool => $v !== null);

        $aliases = $this->cleanStringList($performer->aliases);

        $nameKana = $this->cleanText(is_string($meta['name_kana'] ?? null) ? $meta['name_kana'] : null);
        $nameKana = $nameKana === null ? null : trim($nameKana);
        $crossLink = is_array($meta['cross_link'] ?? null) ? $meta['cross_link'] : [];
        $crossLinkId = $this->firstStringValue([$meta['cross_link_id'] ?? null, $meta['crosslink_id'] ?? null, $crossLink['id'] ?? null]);
        $crossLinkSource = $crossLinkId === null
            ? null
            : ($this->firstStringValue([$meta['cross_link_source'] ?? null, $crossLink['source'] ?? null]) ?? $sourceSlug);

        return new PerformerDraft(
            sourceSlug: $sourceSlug,
            externalId: trim($externalId),
            nameRomaji: $this->cleanText($performer->name ?? (is_string($meta['name_romaji'] ?? null) ? $meta['name_romaji'] : null)),
            nameKanji: $this->cleanText($performer->nameJapanese),
            nameKana: $nameKana !== '' ? $nameKana : null,
            aliases: $aliases,
            profileUrl: $this->cleanText($performer->url ?? $url),
            imageUrl: $this->cleanText($performer->profileImageUrl),
            birthDate: $this->dateYmd($this->cleanText($performer->birthDateRaw ?? (is_string($meta['birth_date_raw'] ?? null) ? $meta['birth_date_raw'] : null))),
            heightCm: $this->heightCm($this->cleanText($performer->heightRaw ?? (is_string($meta['height_raw'] ?? null) ? $meta['height_raw'] : null))),
            bust: $measurements['bust'],
            waist: $measurements['waist'],
            hip: $measurements['hip'],
            cup: $measurements['cup'],
            bloodType: $this->cleanText(is_string($meta['blood_type'] ?? null) ? $meta['blood_type'] : null),
            bioText: $bio,
            debutDate: $this->dateYmd($this->cleanText(is_string($meta['debut_date_raw'] ?? null) ? $meta['debut_date_raw'] : null)),
            location: $this->cleanText(is_string($meta['birthplace'] ?? null) ? $meta['birthplace'] : null),
            attrs: $attrs,
            crossLinkSource: $crossLinkSource,
            crossLinkId: $crossLinkId,
        );
    }

    /**
     * Replace malformed UTF-8 from crawler responses and remove NUL bytes before text reaches PostgreSQL.
     */
    private function cleanText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }

    /** @param list<mixed> $values @return list<string> */
    private function cleanStringList(array $values): array
    {
        $clean = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = $this->cleanText($value);
            if ($value === null || trim($value) === '') {
                continue;
            }

            $clean[] = $value;
        }

        return array_values(array_unique($clean));
    }

    /** @param list<mixed> $values */
    private function firstStringValue(array $values): ?string
    {
        foreach ($values as $value) {
            $string = $this->stringValue($value);
            if ($string !== null) {
                return $string;
            }
        }

        return null;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = $this->cleanText((string) $value);
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** Metadata keys already stored in columns or only noise; everything else is kept in attrs.extra. */
    private const COLUMN_OR_NOISE_KEYS = [
        'external_url', 'raw_profile', 'fields', 'name_kana', 'name_romaji', 'birth_date_raw', 'height_raw', 'size_raw',
        'bust_raw', 'waist_raw', 'hip_raw', 'cup_size', 'cup', 'bust', 'waist', 'hips', 'blood_type', 'birthplace',
        'debut_date_raw', 'zodiac_sign', 'age', 'shoe_size_raw', 'hair_length', 'hair_color', 'favorite_count_raw',
    ];

    /** Longest list kept per extra key, so one crowded profile cannot bloat the row. */
    private const EXTRA_LIST_LIMIT = 200;

    /**
     * The CrawlerX profile fields that have no column (hobby, agency, sns, rating, filmography, related and similar
     * performers, ranking...) are kept as they came, under their own key.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function extraMetadata(array $meta): ?array
    {
        $extra = [];
        foreach ($meta as $key => $value) {
            if (in_array($key, self::COLUMN_OR_NOISE_KEYS, true) || $value === null || $value === '' || $value === []) {
                continue;
            }

            $value = is_array($value) && array_is_list($value) ? array_slice($value, 0, self::EXTRA_LIST_LIMIT) : $value;
            $extra[$key] = $this->cleanValue($value);
        }

        return $extra === [] ? null : $extra;
    }

    private function cleanValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->cleanText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            $key = is_string($key) ? $this->cleanText($key) : $key;
            if ($key !== null) {
                $clean[$key] = $this->cleanValue($item);
            }
        }

        return $clean;
    }

    /**
     * @param array{bust: ?int, waist: ?int, hip: ?int, cup: ?string} $size
     * @param array<string, mixed> $meta
     *
     * @return array{bust: ?int, waist: ?int, hip: ?int, cup: ?string}
     */
    private function measurements(array $size, array $meta): array
    {
        return [
            'bust' => $size['bust'] ?? $this->measurementRaw($meta['bust_raw'] ?? null),
            'waist' => $size['waist'] ?? $this->measurementRaw($meta['waist_raw'] ?? null),
            'hip' => $size['hip'] ?? $this->measurementRaw($meta['hip_raw'] ?? null),
            'cup' => $size['cup'] ?? $this->cupRaw($meta['cup_size'] ?? null) ?? $this->cupRaw($meta['cup'] ?? null),
        ];
    }

    private function measurementRaw(mixed $raw): ?int
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (preg_match('/(\d{1,3})/', $raw, $m) !== 1) {
            return null;
        }

        $value = (int) $m[1];

        return $value >= 30 && $value <= 300 ? $value : null;
    }

    private function cupRaw(mixed $raw): ?string
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        $cup = strtoupper(trim($raw));

        return preg_match('/^[A-Z0-9]{1,20}$/', $cup) === 1 ? $cup : null;
    }

    /** @param array<string, mixed> $meta */
    private function bioText(PerformerDto $performer, array $meta): ?string
    {
        if ($performer->rawProfile !== []) {
            $text = $performer->rawProfile['text'] ?? null;
            if (is_string($text)) {
                $text = $this->cleanText($text);
                if ($text !== null && trim($text) !== '') {
                    return trim($text);
                }
            }
        }

        $raw = $meta['raw_profile'] ?? null;
        if (is_string($raw)) {
            $raw = $this->cleanText($raw);
            if ($raw !== null && trim($raw) !== '') {
                return trim($raw);
            }
        }

        return null;
    }

    private function heightCm(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        if (preg_match('/(\d{2,3})/', $raw, $m) === 1) {
            $cm = (int) $m[1];

            return $cm >= 100 && $cm <= 250 ? $cm : null;
        }

        return null;
    }

    /**
     * @return array{bust: ?int, waist: ?int, hip: ?int, cup: ?string}
     */
    private function parseSize(?string $raw): array
    {
        $empty = ['bust' => null, 'waist' => null, 'hip' => null, 'cup' => null];
        if ($raw === null || trim($raw) === '') {
            return $empty;
        }

        $cup = null;
        if (preg_match('/\(([A-Za-z0-9]+)\)/', $raw, $cupMatch) === 1) {
            $cup = strtoupper($cupMatch[1]);
        }

        // "T161 B83(D) W58 H94": the labeled B/W/H numbers, never the height that comes first.
        if (preg_match('/B\s*(\d{2,3}).*?W\s*(\d{2,3}).*?H\s*(\d{2,3})/i', $raw, $labeled) === 1) {
            return [
                'bust' => (int) $labeled[1],
                'waist' => (int) $labeled[2],
                'hip' => (int) $labeled[3],
                'cup' => $cup,
            ];
        }

        if (preg_match('/(\d{2,3}).*?(\d{2,3}).*?(\d{2,3})/', $raw, $m) === 1) {
            return [
                'bust' => (int) $m[1],
                'waist' => (int) $m[2],
                'hip' => (int) $m[3],
                'cup' => $cup,
            ];
        }

        return ['bust' => null, 'waist' => null, 'hip' => null, 'cup' => $cup];
    }

    private function dateYmd(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $raw = trim($raw);

        // 2000年2月10日, 2003/03/06, 1993-08-16, 1990.10.27
        if (preg_match('/(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $raw, $m) === 1) {
            return $this->validYmd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        // 20230322
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $raw, $m) === 1) {
            return $this->validYmd((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        try {
            return \Carbon\CarbonImmutable::parse($raw)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    private function validYmd(int $year, int $month, int $day): ?string
    {
        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
