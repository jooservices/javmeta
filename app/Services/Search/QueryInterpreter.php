<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Embedding\OllamaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a typed question into validated API filters plus the words left for text search.
 * Only catalog values mentioned in the question are accepted from the model.
 */
final class QueryInterpreter
{
    private const SYSTEM = 'Read a movie and performer catalog question and return one JSON object matching the supplied schema. Always return keywords (the exact words left for text search); include only filter values explicitly present in the question. Do not invent catalog values. Unknown words stay in keywords.';
    private const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'keywords' => ['type' => 'string'],
            'maker' => ['type' => ['string', 'null']],
            'series' => ['type' => ['string', 'null']],
            'genre' => ['type' => ['string', 'null']],
            'actress' => ['type' => ['string', 'null']],
            'blood_type' => ['type' => ['string', 'null']],
            'location' => ['type' => ['string', 'null']],
            'cup' => ['type' => ['string', 'null']],
            'year_from' => ['type' => ['integer', 'null'], 'minimum' => 1950, 'maximum' => 2100],
            'year_to' => ['type' => ['integer', 'null'], 'minimum' => 1950, 'maximum' => 2100],
            'runtime_min' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 999],
            'runtime_max' => ['type' => ['integer', 'null'], 'minimum' => 1, 'maximum' => 999],
            'age_min' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120],
            'age_max' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 120],
            'height_min' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 300],
            'height_max' => ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => 300],
        ],
        'required' => ['keywords'],
        'additionalProperties' => false,
    ];

    public function __construct(private readonly OllamaClient $ollama) {}

    /** @return array{keywords: string, filters: array<string, mixed>, by: string} */
    public function interpret(string $question): array
    {
        $question = trim($question);
        $rules = $this->rules($question);
        $by = 'rules';
        $filters = $rules['filters'];
        $keywords = $rules['keywords'];

        if ((bool) config('javmeta_ai.nl_enabled', true) && $this->shouldUseOllama($question)) {
            $model = $this->ollama->json(self::SYSTEM, $question, self::SCHEMA);
            if (is_array($model)) {
                $read = $this->fromModel($model, $question);
                if ($read['filters'] !== [] || $read['keywords'] !== '') {
                    $by = 'ollama';
                    $filters = [...$rules['filters'], ...$read['filters']];
                    $keywords = $read['keywords'] !== '' ? $read['keywords'] : $rules['keywords'];
                }
            }
        }

        return ['keywords' => trim($keywords), 'filters' => $filters, 'by' => $by];
    }

    private function shouldUseOllama(string $question): bool
    {
        if (mb_strlen($question) < 12) {
            return false;
        }

        if (preg_match('/[\p{Han}\p{Hiragana}\p{Katakana}]/u', $question) === 1) {
            return true;
        }

        return preg_match_all('/[\p{L}\p{N}]+/u', $question) >= 3;
    }

    /** @return array{keywords: string, filters: array<string, mixed>} */
    private function rules(string $q): array
    {
        $filters = [];
        $rest = ' ' . $q . ' ';

        if (preg_match('/(?<![A-Za-z0-9])([A-Za-z]{2,6}-\d{2,5})(?![0-9])/', $rest, $m) === 1) {
            $filters['code'] = strtoupper($m[1]);
            $rest = $this->removeMatch($rest, $m[0]);
        }

        if (preg_match('/(?:longer than|over|more than|dài hơn|trên)\s+(\d{2,3})\s*(?:minutes|min|phút)/iu', $rest, $m) === 1) {
            $filters['runtime_min'] = (int) $m[1];
            $rest = $this->removeMatch($rest, $m[0]);
        }
        if (preg_match('/(?:shorter than|under|less than|ngắn hơn|dưới)\s+(\d{2,3})\s*(?:minutes|min|phút)/iu', $rest, $m) === 1) {
            $filters['runtime_max'] = (int) $m[1];
            $rest = $this->removeMatch($rest, $m[0]);
        }

        if (preg_match('/(?<!\d)((?:19|20)\d{2})\s*(?:-|–|to|đến)\s*((?:19|20)\d{2})(?:\s*年)?(?!\d)/iu', $rest, $m) === 1) {
            [$filters['released_from'], $filters['released_to']] = [$m[1] . '-01-01', $m[2] . '-12-31'];
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:after|since|từ|sau|以降)\s*((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年以降/u', $rest, $m) === 1) {
            $year = $m[1] !== '' ? $m[1] : $m[2];
            $filters['released_from'] = $year . '-01-01';
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:before|until|trước)\s*((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年以前/u', $rest, $m) === 1) {
            $year = $m[1] !== '' ? (int) $m[1] - 1 : (int) $m[2];
            if ($year >= 1950) {
                $filters['released_to'] = $year . '-12-31';
                $rest = $this->removeMatch($rest, $m[0]);
            }
        } elseif (preg_match('/(?:released\s+(?:in\s+)?|năm\s+|year\s+|(?<![A-Za-z])in\s+)((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年/u', $rest, $m) === 1) {
            $year = $m[1] !== '' ? $m[1] : $m[2];
            [$filters['released_from'], $filters['released_to']] = [$year . '-01-01', $year . '-12-31'];
            $rest = $this->removeMatch($rest, $m[0]);
        }

        if (preg_match('/(?:age|aged|ages|tuổi|年齢)\s*(?:between\s+|from\s+|từ\s*)?(\d{1,3})\s*(?:-|–|to|and|đến)\s*(\d{1,3})\s*(?:years?\s*old|tuổi|歳)?/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'age', (int) $m[1], (int) $m[2], 0, 120);
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:age|aged|tuổi|年齢)\s*(?:is\s*)?(\d{1,3})\s*(?:years?\s*old|tuổi|歳)?/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'age', (int) $m[1], (int) $m[1], 0, 120);
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:under|younger than|dưới)\s*(\d{1,3})\s*(?:years?\s*old|tuổi|歳)/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'age', null, (int) $m[1], 0, 120);
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:over|older than|at least|trên)\s*(\d{1,3})\s*(?:years?\s*old|tuổi|歳)/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'age', (int) $m[1], null, 0, 120);
            $rest = $this->removeMatch($rest, $m[0]);
        }

        if (preg_match('/(?:height|cao|身長)\s*(?:is|of|=|:)?\s*(\d{2,3})\s*(?:-|–|to|and|đến)\s*(\d{2,3})\s*(?:cm)?/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'height', (int) $m[1], (int) $m[2], 0, 300);
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:height|cao|身長)\s*(?:is|of|=|:)?\s*(\d{2,3})\s*(?:cm)?/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'height', (int) $m[1], (int) $m[1], 0, 300);
            $rest = $this->removeMatch($rest, $m[0]);
        }

        if (preg_match('/(?:blood\s*(?:type|group)|nhóm\s*máu)\s*(?:is|=|:)?\s*(AB|A|B|O)(?![A-Za-z])/iu', $rest, $m) === 1
            || preg_match('/血液型\s*(AB|A|B|O)(?:型)?/u', $rest, $m) === 1) {
            $value = $this->knownFacet('blood_type', $m[1]);
            if ($value !== null) {
                $filters['blood_type'] = $value;
                $rest = $this->removeMatch($rest, $m[0]);
            }
        }

        if (preg_match('/(?:cup|カップ)\s*(?:size\s*)?([A-H]{1,2})(?![A-Za-z])/iu', $rest, $m) === 1) {
            $value = $this->knownFacet('cup', $m[1]);
            if ($value !== null) {
                $filters['cup'] = $value;
                $rest = $this->removeMatch($rest, $m[0]);
            }
        }

        foreach ($this->facetNames('location') as $location) {
            $quoted = preg_quote($location, '/');
            $pattern = '/(?:\b(?:living|live|based|located)\s+in|\bfrom|\bin|\bat|\bnear|ở|tại|住んで(?:いる)?|在住|所在地\s*[:は]?)\s*' . $quoted . '(?![\p{L}\p{N}])|' . $quoted . '\s*(?:在住|に住(?:む|む人|んでいる)?)/iu';
            if (preg_match($pattern, $rest, $m) === 1) {
                $filters['location'] = $location;
                $rest = $this->removeMatch($rest, $m[0]);
                break;
            }
        }

        foreach (['maker' => 'makers', 'series' => 'series', 'genre' => 'genres'] as $field => $list) {
            foreach ($this->names($list) as $name) {
                $pos = mb_stripos($rest, $name);
                if (mb_strlen($name) >= 3 && $pos !== false && ! isset($filters[$field])) {
                    $filters[$field] = $name;
                    $rest = mb_substr($rest, 0, $pos) . ' ' . mb_substr($rest, $pos + mb_strlen($name));
                    break;
                }
            }
        }

        $actress = $this->performerIn($rest);
        if ($actress !== null) {
            $filters['actress'] = $actress;
            $rest = str_ireplace($actress, ' ', $rest);
        }

        $keywords = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace(
            '/\b(?:movies?|films?|videos?|released|published|with|by|from|the|of|phim|của|và|năm|年|以降|映画|作品|女優|俳優|出演者|の)\b/iu',
            ' ',
            $rest,
        )));

        return ['keywords' => $keywords, 'filters' => $filters];
    }

    /** @param array<string, mixed> $filters */
    private function putRange(array &$filters, string $prefix, ?int $minimum, ?int $maximum, int $floor, int $ceiling): void
    {
        if (($minimum !== null && ($minimum < $floor || $minimum > $ceiling)) || ($maximum !== null && ($maximum < $floor || $maximum > $ceiling))) {
            return;
        }
        if ($minimum !== null && $maximum !== null && $maximum < $minimum) {
            return;
        }
        if ($minimum !== null) {
            $filters[$prefix . '_min'] = $minimum;
        }
        if ($maximum !== null) {
            $filters[$prefix . '_max'] = $maximum;
        }
    }

    /** @param array<string, mixed> $model @return array{keywords: string, filters: array<string, mixed>, invalid: bool} */
    private function fromModel(array $model, string $question): array
    {
        $filters = [];
        $invalid = false;

        foreach (['maker', 'series', 'genre', 'actress', 'blood_type', 'location', 'cup'] as $key) {
            $value = $model[$key] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
                if ($value !== null) {
                    $invalid = true;
                }
                continue;
            }

            $candidate = trim((string) preg_replace('/^[\\p{P}\\p{Z}]+|[\\p{P}\\p{Z}]+$/u', '', trim($value)));
            if (! $this->mentioned($candidate, $question)) {
                $invalid = true;
                continue;
            }
            $known = match ($key) {
                'maker', 'series', 'genre' => $this->knownMovieValue($key, $candidate),
                'actress' => $this->knownPerformer($candidate),
                default => $this->knownFacet($key, $candidate),
            };
            if ($known === null) {
                $invalid = true;
                continue;
            }
            $filters[$key] = $known;
        }

        foreach (['runtime_min' => [1, 999], 'runtime_max' => [1, 999], 'age_min' => [0, 120], 'age_max' => [0, 120], 'height_min' => [0, 300], 'height_max' => [0, 300]] as $key => [$minimum, $maximum]) {
            if (! array_key_exists($key, $model)) {
                continue;
            }
            $value = $model[$key];
            if (! is_numeric($value) || (int) $value < $minimum || (int) $value > $maximum || ! $this->mentionedNumber((int) $value, $question)) {
                $invalid = true;
                continue;
            }
            $filters[$key] = (int) $value;
        }

        $yearFrom = $model['year_from'] ?? null;
        $yearTo = $model['year_to'] ?? null;
        if ($yearFrom !== null && (! is_numeric($yearFrom) || (int) $yearFrom < 1950 || (int) $yearFrom > 2100 || ! $this->mentionedNumber((int) $yearFrom, $question))) {
            $yearFrom = null;
            $invalid = true;
        }
        if ($yearTo !== null && (! is_numeric($yearTo) || (int) $yearTo < 1950 || (int) $yearTo > 2100 || ! $this->mentionedNumber((int) $yearTo, $question))) {
            $yearTo = null;
            $invalid = true;
        }
        if ($yearFrom !== null && $yearTo !== null && (int) $yearTo < (int) $yearFrom) {
            $yearFrom = $yearTo = null;
            $invalid = true;
        }
        if ($yearFrom !== null) {
            $filters['released_from'] = (int) $yearFrom . '-01-01';
        }
        if ($yearTo !== null) {
            $filters['released_to'] = (int) $yearTo . '-12-31';
        }

        foreach (['age', 'height', 'runtime'] as $prefix) {
            $min = $filters[$prefix . '_min'] ?? null;
            $max = $filters[$prefix . '_max'] ?? null;
            if ($min !== null && $max !== null && $max < $min) {
                unset($filters[$prefix . '_min'], $filters[$prefix . '_max']);
                $invalid = true;
            }
        }

        $keywords = $model['keywords'] ?? '';
        if (! is_string($keywords) || mb_strlen($keywords) > 200 || ! $this->mentionedWords($keywords, $question)) {
            $keywords = '';
            $invalid = true;
        }

        return ['keywords' => trim($keywords), 'filters' => $filters, 'invalid' => $invalid];
    }

    private function mentioned(string $value, string $question): bool
    {
        return mb_stripos($question, $value) !== false;
    }

    private function mentionedNumber(int $value, string $question): bool
    {
        return preg_match('/(?<!\d)' . preg_quote((string) $value, '/') . '(?!\d)/u', $question) === 1;
    }

    private function mentionedWords(string $value, string $question): bool
    {
        if (trim($value) === '') {
            return true;
        }
        preg_match_all('/[\p{L}\p{N}]+/u', $value, $tokens);
        foreach ($tokens[0] as $token) {
            if (mb_stripos($question, $token) === false) {
                return false;
            }
        }

        return true;
    }

    private function knownMovieValue(string $field, string $value): ?string
    {
        $column = match ($field) {
            'maker' => 'maker',
            'series' => 'series',
            default => null,
        };
        $needle = mb_strtolower($value);
        if ($column !== null) {
            $known = DB::table('movies')->whereNotNull($column)->whereRaw('LOWER(' . $column . ') = ?', [$needle])->value($column);

            return is_string($known) ? $known : null;
        }

        $known = DB::table('genres')
            ->selectRaw('COALESCE(label_raw, label_normalized) AS value')
            ->whereRaw('LOWER(COALESCE(label_raw, label_normalized)) = ?', [$needle])
            ->value('value');

        return is_string($known) ? $known : null;
    }

    private function knownPerformer(string $value): ?string
    {
        $needle = mb_strtolower($value);
        $row = DB::table('performers')
            ->whereNull('canonical_performer_id')
            ->where(static function ($query) use ($needle): void {
                foreach (['name_romaji', 'name_kanji', 'name_kana'] as $column) {
                    $query->orWhereRaw('LOWER(' . $column . ') = ?', [$needle]);
                }
            })
            ->first(['name_romaji', 'name_kanji', 'name_kana']);
        if ($row === null) {
            return null;
        }

        foreach (['name_romaji', 'name_kanji', 'name_kana'] as $column) {
            if (is_string($row->{$column}) && $row->{$column} !== '') {
                return $row->{$column};
            }
        }

        return null;
    }

    private function knownFacet(string $field, string $value): ?string
    {
        if (! in_array($field, ['blood_type', 'location', 'cup'], true)) {
            return null;
        }
        $known = DB::table('performers')
            ->whereNotNull($field)
            ->where($field, '<>', '')
            ->whereRaw('LOWER(' . $field . ') = ?', [mb_strtolower(trim($value))])
            ->value($field);

        return is_string($known) ? $known : null;
    }

    /** @return list<string> */
    private function facetNames(string $field): array
    {
        if (! in_array($field, ['blood_type', 'location', 'cup'], true)) {
            return [];
        }

        return Cache::remember('javmeta:interpreter:facet:' . $field, 120, static function () use ($field): array {
            return DB::table('performers')->whereNotNull($field)->where($field, '<>', '')->distinct()->orderBy($field)->pluck($field)->map(static fn($value): string => (string) $value)->all();
        });
    }

    /** @return list<string> catalog names, longest first (cached for two minutes) */
    private function names(string $list): array
    {
        return Cache::remember('javmeta:interpreter:' . $list, 120, static function () use ($list): array {
            $names = match ($list) {
                'makers' => DB::table('movies')->whereNotNull('maker')->where('maker', '<>', '')->distinct()->pluck('maker')->all(),
                'series' => DB::table('movies')->whereNotNull('series')->where('series', '<>', '')->distinct()->pluck('series')->all(),
                default => DB::table('genres')->selectRaw('COALESCE(label_raw, label_normalized) AS n')->pluck('n')->all(),
            };
            $names = array_values(array_filter(array_map('strval', $names)));
            usort($names, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

            return $names;
        });
    }

    private function removeMatch(string $text, string $match): string
    {
        return (string) preg_replace('/' . preg_quote($match, '/') . '/iu', ' ', $text, 1);
    }

    private function performerIn(string $text): ?string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $words = array_values(array_filter(array_map(
            static fn(string $word): string => trim((string) preg_replace('/^[\\p{P}\\p{Z}]+|[\\p{P}\\p{Z}]+$/u', '', $word)),
            $words,
        ), static fn(string $word): bool => $word !== ''));
        $candidates = [];
        $wordCount = count($words);
        for ($n = 3; $n >= 2; $n--) {
            for ($i = 0; $i + $n <= $wordCount; $i++) {
                $candidates[] = mb_strtolower(implode(' ', array_slice($words, $i, $n)));
            }
        }
        if ($candidates === []) {
            return null;
        }
        $name = DB::table('performers')
            ->whereNull('canonical_performer_id')
            ->whereIn(DB::raw('LOWER(name_romaji)'), $candidates)
            ->orderByRaw('LENGTH(name_romaji) DESC')
            ->value('name_romaji');

        return is_string($name) ? $name : null;
    }
}
