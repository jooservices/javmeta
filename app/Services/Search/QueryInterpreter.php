<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Embedding\OllamaClient;
use App\Repositories\QueryInterpreterServiceRepository;
use Illuminate\Support\Facades\Cache;

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

    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly QueryInterpreterServiceRepository $repository,
    ) {}

    /** @return array{keywords: string, filters: array<string, mixed>, by: string} */
    public function interpret(string $question): array
    {
        return $this->interpretWithTelemetry($question)['result'];
    }

    /**
     * @return array{
     *     result: array{keywords: string, filters: array<string, mixed>, by: string},
     *     telemetry: array{backend: string, fallback_reason: string|null, ollama_chat_outcome: string, ollama_chat_duration_ms: int|null, ollama_chat_http_status: int|null}
     * }
     */
    public function interpretWithTelemetry(string $question): array
    {
        $question = trim($question);
        $rules = $this->rules($question);
        $by = 'rules';
        $filters = $rules['filters'];
        $keywords = $rules['keywords'];
        $telemetry = [
            'backend' => 'rules',
            'fallback_reason' => null,
            'ollama_chat_outcome' => 'not_attempted',
            'ollama_chat_duration_ms' => null,
            'ollama_chat_http_status' => null,
        ];

        if ((bool) config('javmeta_ai.nl_enabled', true) && $this->shouldUseOllama($question)) {
            $chat = $this->ollama->jsonWithTelemetry(self::SYSTEM, $question, self::SCHEMA);
            $telemetry['ollama_chat_outcome'] = $chat['outcome'];
            $telemetry['ollama_chat_duration_ms'] = $chat['duration_ms'];
            $telemetry['ollama_chat_http_status'] = $chat['http_status'];

            if (is_array($chat['data'])) {
                $read = $this->fromModel($chat['data'], $question);
                if ($read['filters'] !== [] || $read['keywords'] !== '') {
                    $by = 'ollama';
                    $filters = [...$rules['filters'], ...$read['filters']];
                    $keywords = $read['keywords'] !== '' ? $read['keywords'] : $rules['keywords'];
                } else {
                    $telemetry['fallback_reason'] = 'ollama_output_rejected';
                }
            } else {
                $telemetry['fallback_reason'] = 'ollama_' . $chat['outcome'];
            }
        }

        $telemetry['backend'] = $by;

        return [
            'result' => ['keywords' => trim($keywords), 'filters' => $filters, 'by' => $by],
            'telemetry' => $telemetry,
        ];
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

        $filters = [...$filters, ...$this->releaseDateFilters($rest)];

        if (preg_match('/(?:(?<![A-Za-z])(?:age|aged|ages)|tuổi|年齢)\s*(?:between\s+|from\s+|từ\s*)?(\d{1,3})\s*(?:-|–|to|and|đến)\s*(\d{1,3})\s*(?:years?\s*old|tuổi|歳)?/iu', $rest, $m) === 1) {
            $this->putRange($filters, 'age', (int) $m[1], (int) $m[2], 0, 120);
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:(?<![A-Za-z])(?:age|aged)|tuổi|年齢)\s*(?:is\s*)?(\d{1,3})\s*(?:years?\s*old|tuổi|歳)?/iu', $rest, $m) === 1) {
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

        $filters = array_merge($filters, $this->catalogFilters($rest));

        $keywords = $this->stripFillerTerms($rest);

        return ['keywords' => $keywords, 'filters' => $filters];
    }

    /** @return array<string, mixed> */
    private function catalogFilters(string &$rest): array
    {
        $filters = [];
        $actress = $this->performerIn($rest);
        if ($actress !== null) {
            $filters['actress'] = $actress;
            $rest = str_ireplace($actress, ' ', $rest);
        }

        foreach (['maker' => 'makers', 'series' => 'series', 'genre' => 'genres'] as $field => $list) {
            foreach ($this->names($list) as $name) {
                $pattern = preg_match('/[A-Za-z0-9]/', $name) === 1
                    ? '/(?<![\p{L}\p{N}])' . preg_quote($name, '/') . '(?![\p{L}\p{N}])/iu'
                    : '/' . preg_quote($name, '/') . '/u';
                if (preg_match($pattern, $rest, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $minimumMovies = $field === 'genre' || mb_strlen($name) < 3 ? 20 : 1;
                if ($this->repository->movieCountForCatalogValue($field, $name) < $minimumMovies) {
                    continue;
                }

                if ($field === 'genre') {
                    if (! isset($filters['genre'])) {
                        $filters['genre'] = $name;
                    } else {
                        $filters['genre'] = is_array($filters['genre']) ? $filters['genre'] : [$filters['genre']];
                        if (! in_array($name, $filters['genre'], true)) {
                            $filters['genre'][] = $name;
                        }
                    }
                } elseif (! isset($filters[$field])) {
                    $filters[$field] = $name;
                }

                $start = $match[0][1];
                $rest = substr($rest, 0, $start) . ' ' . substr($rest, $start + strlen($match[0][0]));
                if ($field !== 'genre') {
                    break;
                }
            }
        }

        return $filters;
    }

    private function stripFillerTerms(string $text): string
    {
        $text = (string) preg_replace(
            '/\b(?:movies?|films?|videos?|released|published|with|by|from|the|of|phim|của|và|năm)\b/iu',
            ' ',
            $text,
        );
        $text = (string) preg_replace('/(?<=\s)の(?=(?:映画|作品|女優|俳優|出演者))/u', ' ', $text);
        $text = (string) preg_replace(
            '/(?<![\p{L}\p{N}])(?:映画|作品|女優|俳優|出演者)(?=(?:を|は|が|の|に|で|$|[\s、。,.!?]))(?:を|は|が|の|に|で)?/u',
            ' ',
            $text,
        );

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<string, string> */
    private function releaseDateFilters(string &$rest): array
    {
        $filters = [];
        if (preg_match('/(?:this\s+month|tháng\s+này|今月)/iu', $rest, $m) === 1) {
            $start = now()->startOfMonth();
            $filters['released_from'] = $start->toDateString();
            $filters['released_to'] = $start->endOfMonth()->toDateString();
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?<!\d)((?:19|20)\d{2})\s*(?:-|–|to|đến)\s*((?:19|20)\d{2})(?:\s*年)?(?!\d)/iu', $rest, $m) === 1) {
            [$filters['released_from'], $filters['released_to']] = [$m[1] . '-01-01', $m[2] . '-12-31'];
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:after|since|từ|sau|以降)\s*((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年以降/iu', $rest, $m) === 1) {
            $year = $m[1] !== '' ? $m[1] : $m[2];
            $filters['released_from'] = $year . '-01-01';
            $rest = $this->removeMatch($rest, $m[0]);
        } elseif (preg_match('/(?:before|until|trước)\s*((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年以前/iu', $rest, $m) === 1) {
            $year = $m[1] !== '' ? (int) $m[1] - 1 : (int) $m[2];
            if ($year >= 1950) {
                $filters['released_to'] = $year . '-12-31';
                $rest = $this->removeMatch($rest, $m[0]);
            }
        } elseif (preg_match('/(?:released\s+(?:in\s+)?|from\s+|năm\s+|year\s+|(?<![A-Za-z])in\s+)((?:19|20)\d{2})(?:\s*年)?|(?<!\d)((?:19|20)\d{2})\s*年/iu', $rest, $m) === 1) {
            $year = $m[1] !== '' ? $m[1] : $m[2];
            [$filters['released_from'], $filters['released_to']] = [$year . '-01-01', $year . '-12-31'];
            $rest = $this->removeMatch($rest, $m[0]);
        }

        return $filters;
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

            $candidate = $this->stripEdgePunctuation(trim($value));
            if (! $this->mentioned($key, $candidate, $question)) {
                $invalid = true;
                continue;
            }
            $known = match ($key) {
                'maker', 'series', 'genre' => $this->knownMovieValue($key, $candidate),
                'actress' => $this->knownPerformer($candidate),
                default => $this->knownFacet($key, $candidate),
            };
            if ($known !== null && in_array($key, ['maker', 'series', 'genre'], true)) {
                $minimumMovies = $key === 'genre' ? 20 : 1;
                if ($this->repository->movieCountForCatalogValue($key, $known) < $minimumMovies) {
                    $known = null;
                }
            }
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

    private function mentioned(string $field, string $value, string $question): bool
    {
        if (in_array($field, ['blood_type', 'cup'], true) && mb_strlen($value) <= 2) {
            return preg_match('/(?<![A-Za-z0-9])' . preg_quote($value, '/') . '(?![A-Za-z0-9])/iu', $question) === 1;
        }

        if (in_array($field, ['maker', 'series', 'genre', 'actress'], true) && preg_match('/[A-Za-z0-9]/', $value) === 1) {
            return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($value, '/') . '(?![\p{L}\p{N}])/iu', $question) === 1;
        }

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
        return $this->repository->knownMovieValue($field, $value);
    }

    private function knownPerformer(string $value): ?string
    {
        return $this->repository->knownPerformer($value);
    }

    private function knownFacet(string $field, string $value): ?string
    {
        return $this->repository->knownFacet($field, $value);
    }

    /** @return list<string> */
    private function facetNames(string $field): array
    {
        if (! in_array($field, ['blood_type', 'location', 'cup'], true)) {
            return [];
        }

        return Cache::remember('javmeta:interpreter:facet:' . $field, 120, fn(): array => $this->repository->facetNames($field));
    }

    /** @return list<string> catalog names, longest first (cached for two minutes) */
    private function names(string $list): array
    {
        return Cache::remember('javmeta:interpreter:' . $list, 120, function () use ($list): array {
            $names = $this->repository->catalogNames($list);
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
            fn(string $word): string => $this->stripEdgePunctuation($word),
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
        return $this->repository->performerNameIn($candidates);
    }

    /** Removes punctuation and separators from both ends of a word. */
    private function stripEdgePunctuation(string $word): string
    {
        return trim((string) preg_replace('/(?:^[\\p{P}\\p{Z}]+)|(?:[\\p{P}\\p{Z}]+$)/u', '', $word));
    }
}
