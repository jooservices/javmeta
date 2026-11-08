<?php

declare(strict_types=1);

namespace App\Services\Normalize;

use App\Data\Crawl\PerformerDraft;
use DateTimeInterface;
use Throwable;

/** Applies the canonical rules used at crawl, persistence, backfill, and facet boundaries. */
final class PerformerAttributeNormalizer
{
    /** @var array<string, mixed> */
    private array $rules;

    /** @var array<string, string>|null */
    private ?array $locationIndex = null;

    /** @param array<string, mixed>|null $rules */
    public function __construct(
        ?array $rules = null,
        private readonly ?NormalizationRejectionCounter $counter = null,
    ) {
        $this->rules = $rules ?? $this->configuredRules();
    }

    /** @return array{value: ?string, rh: ?string} */
    public function bloodType(mixed $raw, string $source, bool $recordRejection = true): array
    {
        if ($raw === null || $raw === '') {
            return ['value' => null, 'rh' => null];
        }
        if (! is_string($raw)) {
            $this->reject('blood_type', $source, $recordRejection);

            return ['value' => null, 'rh' => null];
        }

        $value = mb_strtoupper($this->normalizeText($raw), 'UTF-8');
        if ($this->isJunk('blood_type', $value)) {
            $this->reject('blood_type', $source, $recordRejection);

            return ['value' => null, 'rh' => null];
        }

        $value = preg_replace('/(?:(?:^(?:BLOOD\s+)?TYPE\s*[:：]?\s*)|(?:\s+(?:BLOOD\s+)?TYPE$))/u', '', $value) ?? $value;
        $value = str_replace(['血液型', '型'], '', $value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $rh = null;
        if (preg_match('/(?:RH\s*)?([+-])$/u', $value, $match) === 1) {
            $rh = $match[1];
            $value = substr($value, 0, -strlen($match[0]));
        }

        $configured = array_map(static fn(mixed $item): string => mb_strtoupper((string) $item, 'UTF-8'), $this->listRule('blood_types'));
        $allowed = array_values(array_intersect(['A', 'B', 'O', 'AB'], $configured));
        if (in_array($value, $allowed, true)) {
            return ['value' => $value, 'rh' => $rh];
        }

        $this->reject('blood_type', $source, $recordRejection);

        return ['value' => null, 'rh' => null];
    }

    public function bloodRh(mixed $raw, string $source, bool $recordRejection = true): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw) && ! is_int($raw)) {
            $this->reject('blood_rh', $source, $recordRejection);

            return null;
        }

        $value = mb_strtoupper($this->normalizeText((string) $raw), 'UTF-8');
        if ($this->isJunk('blood_rh', $value)) {
            $this->reject('blood_rh', $source, $recordRejection);

            return null;
        }
        $value = preg_replace('/^RH\s*/u', '', $value) ?? $value;
        $normalized = match ($value) {
            '+', 'POSITIVE', 'POS' => '+',
            '-', 'NEGATIVE', 'NEG' => '-',
            default => null,
        };
        if ($normalized !== null) {
            return $normalized;
        }

        $this->reject('blood_rh', $source, $recordRejection);

        return null;
    }

    public function cup(mixed $raw, string $source, bool $recordRejection = true): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw)) {
            $this->reject('cup', $source, $recordRejection);

            return null;
        }

        $value = mb_strtoupper($this->normalizeText($raw), 'UTF-8');
        if ($this->isJunk('cup', $value)) {
            $this->reject('cup', $source, $recordRejection);

            return null;
        }
        $value = preg_replace('/カップ\s*$/u', '', $value) ?? $value;
        $value = preg_replace('/\s+/u', '', $value) ?? $value;
        $configured = array_map(static fn(mixed $item): string => mb_strtoupper((string) $item, 'UTF-8'), $this->listRule('cups'));
        $allowed = array_values(array_intersect(range('A', 'K'), $configured));
        if (preg_match('/^[A-K]$/u', $value) === 1 && in_array($value, $allowed, true)) {
            return $value;
        }

        $this->reject('cup', $source, $recordRejection);

        return null;
    }

    public function measurement(string $field, mixed $raw, string $source, bool $recordRejection = true): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = null;
        if (is_int($raw)) {
            if ($this->isJunk($field, (string) $raw)) {
                $this->reject($field, $source, $recordRejection);

                return null;
            }
            $value = $raw;
        } elseif (is_string($raw)) {
            $normalized = mb_strtoupper($this->normalizeText($raw), 'UTF-8');
            if ($this->isJunk($field, $normalized)) {
                $this->reject($field, $source, $recordRejection);

                return null;
            }
            if (preg_match('/^(\d{1,3})\s*(?:CM)?$/u', $normalized, $match) === 1) {
                $value = (int) $match[1];
            }
        }

        $range = $this->range($field);
        if ($value !== null && $value >= (int) $range['min'] && $value <= (int) $range['max']) {
            return $value;
        }

        $this->reject($field, $source, $recordRejection);

        return null;
    }

    public function date(string $field, mixed $raw, string $source, bool $recordRejection = true): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_string($raw) && $this->isJunk($field, $this->normalizeText($raw))) {
            $this->reject($field, $source, $recordRejection);

            return null;
        }

        $value = $this->parseDate($raw);
        $range = $this->range($field);
        $maximum = $range['max'] === 'today' ? date('Y-m-d') : (string) $range['max'];
        if ($value !== null && $value >= (string) $range['min'] && $value <= $maximum) {
            return $value;
        }

        $this->reject($field, $source, $recordRejection);

        return null;
    }

    public function location(mixed $raw, string $source, bool $recordRejection = true): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_string($raw)) {
            $this->reject('location', $source, $recordRejection);

            return null;
        }

        $value = $this->normalizeText($raw);
        if ($this->isJunk('location', $value)) {
            $this->reject('location', $source, $recordRejection);

            return null;
        }

        $canonical = $this->locationIndex()[$this->locationKey($value)] ?? null;
        if (is_string($canonical) && $canonical !== '') {
            return $canonical;
        }

        $this->reject('location', $source, $recordRejection);

        return null;
    }

    public function facetValue(string $field, mixed $raw): ?string
    {
        return match ($field) {
            'cup' => $this->cup($raw, 'facet', false),
            'blood_type' => $this->bloodType($raw, 'facet', false)['value'],
            'location' => $this->location($raw, 'facet', false),
            default => null,
        };
    }

    public function normalizeDraft(PerformerDraft $draft, bool $recordRejections = true): PerformerDraft
    {
        $blood = $this->bloodType($draft->bloodType, $draft->sourceSlug, $recordRejections);

        return new PerformerDraft(
            sourceSlug: $draft->sourceSlug,
            externalId: $draft->externalId,
            nameRomaji: $draft->nameRomaji,
            nameKanji: $draft->nameKanji,
            nameKana: $draft->nameKana,
            aliases: $draft->aliases,
            profileUrl: $draft->profileUrl,
            imageUrl: $draft->imageUrl,
            birthDate: $this->date('birth_date', $draft->birthDate, $draft->sourceSlug, $recordRejections),
            heightCm: $this->measurement('height_cm', $draft->heightCm, $draft->sourceSlug, $recordRejections),
            bust: $this->measurement('bust', $draft->bust, $draft->sourceSlug, $recordRejections),
            waist: $this->measurement('waist', $draft->waist, $draft->sourceSlug, $recordRejections),
            hip: $this->measurement('hip', $draft->hip, $draft->sourceSlug, $recordRejections),
            cup: $this->cup($draft->cup, $draft->sourceSlug, $recordRejections),
            bloodType: $blood['value'],
            bioText: $draft->bioText,
            debutDate: $this->date('debut_date', $draft->debutDate, $draft->sourceSlug, $recordRejections),
            location: $this->location($draft->location, $draft->sourceSlug, $recordRejections),
            attrs: $draft->attrs,
            crossLinkSource: $draft->crossLinkSource,
            crossLinkId: $draft->crossLinkId,
            bloodRh: $this->bloodRh($draft->bloodRh, $draft->sourceSlug, $recordRejections) ?? $blood['rh'],
        );
    }

    /** @param array<string, mixed> $attributes
     *  @return array<string, mixed>
     */
    public function normalizeStoredAttributes(array $attributes, string $source, bool $recordRejections = false): array
    {
        $blood = $this->bloodType($attributes['blood_type'] ?? null, $source, $recordRejections);

        return [
            'birth_date' => $this->date('birth_date', $attributes['birth_date'] ?? null, $source, $recordRejections),
            'height_cm' => $this->measurement('height_cm', $attributes['height_cm'] ?? null, $source, $recordRejections),
            'bust' => $this->measurement('bust', $attributes['bust'] ?? null, $source, $recordRejections),
            'waist' => $this->measurement('waist', $attributes['waist'] ?? null, $source, $recordRejections),
            'hip' => $this->measurement('hip', $attributes['hip'] ?? null, $source, $recordRejections),
            'cup' => $this->cup($attributes['cup'] ?? null, $source, $recordRejections),
            'blood_type' => $blood['value'],
            'blood_rh' => $this->bloodRh($attributes['blood_rh'] ?? null, $source, $recordRejections) ?? $blood['rh'],
            'debut_date' => $this->date('debut_date', $attributes['debut_date'] ?? null, $source, $recordRejections),
            'location' => $this->location($attributes['location'] ?? null, $source, $recordRejections),
        ];
    }

    private function parseDate(mixed $raw): ?string
    {
        if ($raw instanceof DateTimeInterface) {
            return $raw->format('Y-m-d');
        }
        if (! is_string($raw)) {
            return null;
        }

        $raw = $this->normalizeText($raw);
        $databaseDatePattern = '/^(\d{4}-\d{2}-\d{2})(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/u';
        if (preg_match($databaseDatePattern, $raw, $match) === 1) {
            [$year, $month, $day] = array_map('intval', explode('-', $match[1]));

            return checkdate($month, $day, $year) ? $match[1] : null;
        }
        if (preg_match('/^(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})\s*日?$/u', $raw, $match) === 1
            || preg_match('/^(\d{4})(\d{2})(\d{2})$/u', $raw, $match) === 1) {
            return checkdate((int) $match[2], (int) $match[3], (int) $match[1])
                ? sprintf('%04d-%02d-%02d', (int) $match[1], (int) $match[2], (int) $match[3])
                : null;
        }

        return null;
    }

    private function normalizeText(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');
        $value = mb_convert_kana($value, 'ans', 'UTF-8');
        $value = strtr($value, ['－' => '-', '−' => '-', '–' => '-', '—' => '-', '＋' => '+']);

        return trim($value);
    }

    private function isJunk(string $field, string $value): bool
    {
        $key = $this->locationKey($value);
        foreach ($this->listRule('junk_tokens', $field) as $token) {
            if ($this->locationKey((string) $token) === $key) {
                return true;
            }
        }

        return $value === '';
    }

    private function locationKey(string $value): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', $this->normalizeText($value)) ?? $value, 'UTF-8');
    }

    /** @return array{min: int|string, max: int|string} */
    private function range(string $field): array
    {
        $ranges = $this->rules['field_ranges'] ?? [];
        $range = is_array($ranges) ? ($ranges[$field] ?? null) : null;
        if (is_array($range) && array_key_exists('min', $range) && array_key_exists('max', $range)) {
            return ['min' => $range['min'], 'max' => $range['max']];
        }

        return match ($field) {
            'height_cm' => ['min' => 100, 'max' => 250],
            'bust' => ['min' => 50, 'max' => 250],
            'waist' => ['min' => 30, 'max' => 200],
            'hip' => ['min' => 50, 'max' => 250],
            'birth_date' => ['min' => '1900-01-01', 'max' => 'today'],
            default => ['min' => '1950-01-01', 'max' => 'today'],
        };
    }

    /** @return list<mixed> */
    private function listRule(string $key, ?string $field = null): array
    {
        $value = $this->rules[$key] ?? [];
        if ($field !== null && is_array($value)) {
            $value = $value[$field] ?? [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /** @return array<string, string> */
    private function locationIndex(): array
    {
        if ($this->locationIndex !== null) {
            return $this->locationIndex;
        }

        $mapping = $this->rules['location_aliases'] ?? [];
        $index = [];
        if (is_array($mapping)) {
            foreach ($mapping as $alias => $canonical) {
                if (is_string($alias) && is_string($canonical) && $alias !== '' && $canonical !== '') {
                    $index[$this->locationKey($alias)] = $canonical;
                }
            }
        }

        return $this->locationIndex = $index;
    }

    private function reject(string $field, string $source, bool $record): void
    {
        if ($record) {
            try {
                ($this->counter ?? new NormalizationRejectionCounter())->increment('performer', $field, $source);
            } catch (Throwable) {
                // Validation must not fail because the metrics store is unavailable.
            }
        }
    }

    /** @return array<string, mixed> */
    private function configuredRules(): array
    {
        try {
            $rules = config('javmeta_normalization');
            if (is_array($rules)) {
                return $rules;
            }
        } catch (Throwable) {
            // Standalone unit tests do not boot a Laravel configuration repository.
        }

        $rules = require dirname(__DIR__, 3) . '/config/javmeta_normalization.php';

        return is_array($rules) ? $rules : [];
    }
}
