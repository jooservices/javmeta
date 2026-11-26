<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Discovery;

use App\Repositories\Api\V2\InterpretationRepository;
use Normalizer;

/**
 * Reads a typed question as filter conditions (the chips of the "I understood" bar). Rule based:
 * movies by year, runtime, genre, maker, series and a performer's full name; performers by age, blood type, cup, height and location.
 * Each condition carries the filter keys it sets, so the client can switch it off by its id.
 */
final class InterpretationService
{
    private const MOVIE_STOP = ['phim', 'movie', 'movies', 'cua', 'voi', 'nam', 'nhung', 'the', 'and', 'for'];

    private const PERFORMER_STOP = ['dien', 'vien', 'nu', 'tuoi', 'nhom', 'mau', 'cao', 'cup', 'cua', 'voi', 'the', 'and', 'for'];

    private const AGE_UNIT = '(?:tuoi|age|yo|years?)';

    private const CATALOGUE_SECONDS = 600;

    public function __construct(private readonly InterpretationRepository $repository, private readonly ReadCache $cache) {}

    /**
     * @param list<string> $ignore ids of conditions the client switched off
     * @return array{filters: array<string, string>, conditions: list<array<string, mixed>>, ignored: list<array<string, mixed>>, residual: string}
     */
    public function movies(string $text, array $ignore): array
    {
        $t = $this->norm($text);
        $conds = [...$this->yearAndRuntime($t), ...$this->catalogueTerms($t)];
        $performer = $this->performer($t);
        $conds = $performer === null ? $conds : [...$conds, $performer];

        $kept = array_values(array_filter($conds, static fn(array $c): bool => ! in_array($c['id'], $ignore, true)));
        $ignored = array_values(array_filter($conds, static fn(array $c): bool => in_array($c['id'], $ignore, true)));
        $residual = $this->movieResidual($t, $conds);
        $genre = $this->hasKey($kept, 'genre') ? null : $this->bareGenre($t, $ignore);

        if ($genre !== null) {
            $kept[] = $genre;
            $residual = '';
        }

        return $this->answer($kept, $ignored, $residual, true);
    }

    /**
     * @param list<string> $ignore
     * @return array{filters: array<string, string>, conditions: list<array<string, mixed>>, ignored: list<array<string, mixed>>, residual: string}
     */
    public function performers(string $text, array $ignore): array
    {
        $left = ' ' . $this->norm($text) . ' ';
        $conds = [];

        foreach ($this->performerRules() as $rule) {
            if (preg_match($rule['pattern'], $left, $m) !== 1) {
                continue;
            }

            $cond = $rule['build']($m);

            if ($cond === null) {
                continue;
            }

            $left = $this->replaceFirst($m[0], ' ', $left);
            $conds[] = $cond;
        }

        $location = $this->location($left);

        if ($location !== null && ! in_array($location['cond']['id'], $ignore, true)) {
            $conds[] = $location['cond'];
            $left = $location['left'];
        }

        $kept = array_values(array_filter($conds, static fn(array $c): bool => ! in_array($c['id'], $ignore, true)));
        $rest = array_filter(preg_split('/\s+/', $left) ?: [], static fn(string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::PERFORMER_STOP, true));

        return $this->answer($kept, [], implode(' ', $rest), false);
    }

    /** @return list<array<string, mixed>> */
    private function yearAndRuntime(string $t): array
    {
        $conds = [];

        if (preg_match('/\b(?:19|20)\d{2}\b/', $t, $m) === 1) {
            $conds[] = $this->cond('Năm', $m[0], ['released_from' => $m[0], 'released_to' => $m[0]], [$m[0]]);
        }

        if (preg_match('/(?:duoi|under|<|less than|ngan hon)\s*(\d{2,3})/', $t, $m) === 1) {
            $conds[] = $this->cond('Thời lượng', "≤ {$m[1]} phút", ['runtime_max' => $m[1]]);
        }

        if (preg_match('/(?:tren|over|>|more than|dai hon)\s*(\d{2,3})/', $t, $m) === 1) {
            $conds[] = $this->cond('Thời lượng', "≥ {$m[1]} phút", ['runtime_min' => $m[1]]);
        }

        return $conds;
    }

    /** @return list<array<string, mixed>> */
    private function catalogueTerms(string $t): array
    {
        $conds = [];
        $genres = array_slice(array_filter($this->words('genres'), static fn(array $g): bool => $g['n'] >= 100 && mb_strlen($g['name']) > 3 && str_contains($t, $g['nn'])), 0, 3);

        if ($genres !== []) {
            $names = array_column($genres, 'name');
            $conds[] = $this->cond('Thể loại', implode(', ', $names), ['genre' => implode(',', $names)], array_column($genres, 'nn'));
        }

        foreach ([['makers', 20, 2, 'Hãng', 'maker'], ['series', 10, 3, 'Series', 'series']] as [$list, $min, $length, $label, $key]) {
            $best = $this->longestMatch($this->words($list), $t, $min, $length);

            if ($best !== null) {
                $conds[] = $this->cond($label, $best['name'], [$key => $best['name']], [$best['nn']]);
            }
        }

        return $conds;
    }

    /**
     * @param list<array{name: string, n: int, nn: string}> $words
     * @return array{name: string, n: int, nn: string}|null
     */
    private function longestMatch(array $words, string $t, int $minCount, int $minLength): ?array
    {
        $found = array_filter($words, static fn(array $w): bool => $w['n'] >= $minCount && mb_strlen($w['name']) > $minLength && str_contains($t, $w['nn']));
        usort($found, static fn(array $a, array $b): int => mb_strlen($b['nn']) <=> mb_strlen($a['nn']));

        return $found[0] ?? null;
    }

    /** @return array<string, mixed>|null */
    private function performer(string $t): ?array
    {
        $words = array_values(array_filter(preg_split('/\s+/', $t) ?: [], static fn(string $w): bool => $w !== ''));
        $candidates = [];

        for ($i = 0, $n = count($words); $i < $n; $i++) {
            for ($j = $i + 1; $j <= min($n, $i + 4); $j++) {
                $candidate = implode(' ', array_slice($words, $i, $j - $i));

                if (mb_strlen($candidate) > 4) {
                    $candidates[$candidate] = $candidate;
                }
            }
        }

        $found = $candidates === [] ? null : $this->repository->performerNamed(array_values($candidates));

        return $found === null ? null : $this->cond('Diễn viên', $found['name'], ['actress_id' => (string) $found['id']], [$this->norm($found['name'])]);
    }

    /**
     * What is left of the text once the recognised parts are taken out.
     *
     * @param list<array<string, mixed>> $conds
     */
    private function movieResidual(string $t, array $conds): string
    {
        $left = (string) preg_replace('/(duoi|under|tren|over|<|>|less than|more than)\s*\d+\s*(phut|min|minutes)?/', ' ', $t);

        foreach ($conds as $cond) {
            foreach ($cond['used'] as $term) {
                $left = $this->replaceFirst($term, ' ', $left);
            }
        }

        $rest = array_filter(preg_split('/\s+/', $left) ?: [], static fn(string $w): bool => mb_strlen($w) > 2 && ! in_array($w, self::MOVIE_STOP, true));

        return implode(' ', $rest);
    }

    /**
     * A bare word that is exactly a genre word is read as a topic.
     *
     * @param list<string> $ignore
     * @return array<string, mixed>|null
     */
    private function bareGenre(string $t, array $ignore): ?array
    {
        $typed = array_values(array_filter(preg_split('/\s+/', $t) ?: [], static fn(string $w): bool => mb_strlen($w) >= 4));

        foreach ($this->words('genres') as $genre) {
            if ($genre['n'] < 100 || mb_strlen($genre['name']) <= 3 || array_intersect($typed, explode(' ', $genre['nn'])) === []) {
                continue;
            }

            $cond = $this->cond('Thể loại', $genre['name'], ['genre' => $genre['name']]);

            return in_array($cond['id'], $ignore, true) ? null : $cond;
        }

        return null;
    }

    /** @return list<array{pattern: string, build: callable(list<string>): ?array<string, mixed>}> */
    private function performerRules(): array
    {
        $unit = self::AGE_UNIT;
        $range = static fn(int $a, int $b): bool => $a >= 18 && $b <= 80 && $a <= $b;

        return [
            ['pattern' => "/(?:{$unit}\\s*)(\\d{2})\\s*(?:-|–|den|to)\\s*(\\d{2})\\b|\\b(\\d{2})\\s*(?:-|–|den|to)\\s*(\\d{2})\\s*{$unit}/u", 'build' => static function (array $m) use ($range): ?array {
                $a = (int) (($m[1] ?? '') !== '' ? $m[1] : $m[3]);
                $b = (int) (($m[2] ?? '') !== '' ? $m[2] : $m[4]);

                return $range($a, $b) ? self::condition('Tuổi', "{$a}–{$b}", ['age_min' => (string) $a, 'age_max' => (string) $b], "Tuổi:{$a}-{$b}") : null;
            }],
            ['pattern' => "/(?:duoi|under|<)\\s*(\\d{2})\\s*{$unit}/", 'build' => static fn(array $m): array => self::condition('Tuổi', "≤ {$m[1]}", ['age_max' => $m[1]], "Tuổi:≤{$m[1]}")],
            ['pattern' => "/(?:tren|over|>)\\s*(\\d{2})\\s*{$unit}/", 'build' => static fn(array $m): array => self::condition('Tuổi', "≥ {$m[1]}", ['age_min' => $m[1]], "Tuổi:≥{$m[1]}")],
            ['pattern' => "/\\b(\\d{2})\\s*{$unit}\\b|\\b{$unit}\\s*(\\d{2})\\b/", 'build' => static function (array $m): ?array {
                $age = ($m[1] ?? '') !== '' ? $m[1] : $m[2];

                return (int) $age >= 18 && (int) $age <= 80 ? self::condition('Tuổi', $age, ['age_min' => $age, 'age_max' => $age], "Tuổi:{$age}") : null;
            }],
            ['pattern' => '/(?:nhom mau|blood(?:\s*type)?)\s*(ab|a|b|o)\b/', 'build' => static fn(array $m): array => self::condition('Nhóm máu', strtoupper($m[1]), ['blood_type' => strtoupper($m[1])])],
            ['pattern' => '/\bcup\s*([a-k])\b|\b([a-k])\s*cup\b/', 'build' => static function (array $m): array {
                $cup = strtoupper(($m[1] ?? '') !== '' ? $m[1] : $m[2]);

                return self::condition('Cup', $cup, ['cup' => $cup]);
            }],
            ['pattern' => '/(?:cao|height)\s*(?:(tren|over|>|duoi|under|<)\s*)?(\d{3})\s*(?:cm)?/', 'build' => static function (array $m): array {
                $way = $m[1] ?? '';
                $height = $m[2];
                $up = preg_match('/tren|over|>/', $way) === 1;
                $down = preg_match('/duoi|under|</', $way) === 1;
                $filters = $up ? ['height_min' => $height] : ($down ? ['height_max' => $height] : ['height_min' => $height, 'height_max' => $height]);

                return self::condition('Chiều cao', ($up ? '≥ ' : ($down ? '≤ ' : '')) . "{$height} cm", $filters, "Chiều cao:{$way}{$height}");
            }],
        ];
    }

    /** @return array{cond: array<string, mixed>, left: string}|null */
    private function location(string $left): ?array
    {
        foreach ($this->words('locations') as $location) {
            if (mb_strlen($location['nn']) >= 2 && str_contains($left, $location['nn'])) {
                return ['cond' => self::condition('Nơi ở', $location['name'], ['location' => $location['name']]), 'left' => $this->replaceFirst($location['nn'], ' ', $left)];
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $kept
     * @param list<array<string, mixed>> $ignored
     * @return array{filters: array<string, string>, conditions: list<array<string, mixed>>, ignored: list<array<string, mixed>>, residual: string}
     */
    private function answer(array $kept, array $ignored, string $residual, bool $residualFilters): array
    {
        $filters = [];

        foreach ($kept as $cond) {
            $filters = array_merge($filters, $cond['filters']);
        }

        if ($residualFilters && $residual !== '') {
            $filters['q'] = $residual;
        }

        $public = static fn(array $c): array => ['k' => $c['k'], 'v' => $c['v'], 'id' => $c['id'], 'keys' => $c['keys']];

        return ['filters' => $filters, 'conditions' => array_map($public, $kept), 'ignored' => array_map($public, $ignored), 'residual' => $residual];
    }

    /** @param list<array<string, mixed>> $conds */
    private function hasKey(array $conds, string $key): bool
    {
        foreach ($conds as $cond) {
            if (in_array($key, $cond['keys'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $used the text the condition took out of the question
     * @return array<string, mixed>
     */
    private function cond(string $k, string $v, array $filters, array $used = []): array
    {
        return self::condition($k, $v, $filters, null, $used);
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $used
     * @return array<string, mixed>
     */
    private static function condition(string $k, string $v, array $filters, ?string $id = null, array $used = []): array
    {
        return ['k' => $k, 'v' => $v, 'id' => $id ?? "{$k}:{$v}", 'keys' => array_keys($filters), 'filters' => $filters, 'used' => $used];
    }

    /** @return list<array{name: string, n: int, nn: string}> */
    private function words(string $kind): array
    {
        return $this->cache->remember("interpretation:{$kind}", self::CATALOGUE_SECONDS, fn(): array => array_map(
            fn(array $row): array => ['name' => $row['name'], 'n' => $row['n'], 'nn' => $this->norm($row['name'])],
            $this->repository->catalogue($kind),
        ));
    }

    private function norm(string $text): string
    {
        return (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', (string) Normalizer::normalize(str_replace('đ', 'd', mb_strtolower($text)), Normalizer::FORM_KD));
    }

    private function replaceFirst(string $needle, string $with, string $haystack): string
    {
        $at = $needle === '' ? false : strpos($haystack, $needle);

        return $at === false ? $haystack : substr_replace($haystack, $with, $at, strlen($needle));
    }
}
