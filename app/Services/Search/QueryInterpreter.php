<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Services\Embedding\OllamaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Turns a typed question into filters plus the words left for the text search ("Mankitsu drama released in 2024 longer than
 * 100 minutes"). Rules always work: years, runtimes, movie codes and names that exist in the catalog (maker, series, genre,
 * performer). When a local Ollama answers, its reading is used first and the rules fill the gaps. The text never goes to a
 * cloud model (docs/decisions/cloud-ai-policy-decision.md).
 */
final class QueryInterpreter
{
    private const SYSTEM = 'You turn a search question about a movie catalog into JSON with these optional keys: "keywords" (string with the words that remain for a text search), "maker", "series", "genre", "actress" (strings), "year_from", "year_to", "runtime_min", "runtime_max" (integers). Answer with the JSON object only. Use only what the question says; do not invent values.';

    public function __construct(private readonly OllamaClient $ollama) {}

    /** @return array{keywords: string, filters: array<string, mixed>, by: string} */
    public function interpret(string $question): array
    {
        $question = trim($question);
        $rules = $this->rules($question);
        $by = 'rules';
        $filters = $rules['filters'];
        $keywords = $rules['keywords'];

        if ((bool) config('javmeta_ai.nl_enabled', true) && mb_strlen($question) >= 12 && str_word_count($question) >= 3) {
            $model = $this->ollama->json(self::SYSTEM, $question);
            if (is_array($model)) {
                $read = $this->fromModel($model);
                if ($read['filters'] !== [] || $read['keywords'] !== '') {
                    $by = 'ollama';
                    $filters = [...$rules['filters'], ...$read['filters']];
                    $keywords = $read['keywords'] !== '' ? $read['keywords'] : $keywords;
                }
            }
        }

        return ['keywords' => trim($keywords), 'filters' => $filters, 'by' => $by];
    }

    /** @return array{keywords: string, filters: array<string, mixed>} */
    private function rules(string $q): array
    {
        $filters = [];
        $rest = ' ' . $q . ' ';

        // A movie code such as ABC-123 is a code filter on its own.
        if (preg_match('/(?<![A-Za-z0-9])([A-Za-z]{2,6}-\d{2,5})(?![0-9])/', $rest, $m) === 1) {
            $filters['code'] = strtoupper($m[1]);
            $rest = str_replace($m[0], ' ', $rest);
        }
        // Runtimes: "longer than 100 minutes", "under 90 min", "dài hơn 100 phút".
        if (preg_match('/(?:longer than|over|more than|dài hơn|trên)\s+(\d{2,3})\s*(?:minutes|min|phút)/iu', $rest, $m) === 1) {
            $filters['runtime_min'] = (int) $m[1];
            $rest = str_replace($m[0], ' ', $rest);
        }
        if (preg_match('/(?:shorter than|under|less than|ngắn hơn|dưới)\s+(\d{2,3})\s*(?:minutes|min|phút)/iu', $rest, $m) === 1) {
            $filters['runtime_max'] = (int) $m[1];
            $rest = str_replace($m[0], ' ', $rest);
        }
        // Years: a range "2020-2023", "after 2021", "before 2019", or a single year.
        if (preg_match('/\b((?:19|20)\d{2})\s*(?:-|–|to|đến)\s*((?:19|20)\d{2})\b/iu', $rest, $m) === 1) {
            [$filters['released_from'], $filters['released_to']] = [$m[1] . '-01-01', $m[2] . '-12-31'];
            $rest = str_replace($m[0], ' ', $rest);
        } elseif (preg_match('/\b(?:after|since|từ)\s+((?:19|20)\d{2})\b/iu', $rest, $m) === 1) {
            $filters['released_from'] = $m[1] . '-01-01';
            $rest = str_replace($m[0], ' ', $rest);
        } elseif (preg_match('/\b(?:before|until|trước)\s+((?:19|20)\d{2})\b/iu', $rest, $m) === 1) {
            $filters['released_to'] = ($m[1] - 1) . '-12-31';
            $rest = str_replace($m[0], ' ', $rest);
        } elseif (preg_match('/(?:released\s+(?:in\s+)?|năm\s+|\bin\s+)((?:19|20)\d{2})\b/iu', $rest, $m) === 1 || preg_match('/^\s*((?:19|20)\d{2})\s*$/u', $rest, $m) === 1) {
            [$filters['released_from'], $filters['released_to']] = [$m[1] . '-01-01', $m[1] . '-12-31'];
            $rest = str_replace($m[0], ' ', $rest);
        }

        // Names that exist in the catalog: longest first, so "Sample Maker 03" wins over "Sample".
        foreach (['maker' => 'makers', 'series' => 'series', 'genre' => 'genres'] as $field => $list) {
            foreach ($this->names($list) as $name) {
                if (mb_strlen($name) < 3 || isset($filters[$field])) {
                    continue;
                }
                $pos = mb_stripos($rest, $name);
                if ($pos !== false) {
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

        $keywords = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/\b(?:movies?|films?|videos?|released|with|by|from|the|of|phim|của|và)\b/iu', ' ', $rest)));

        return ['keywords' => $keywords, 'filters' => $filters];
    }

    /** @param array<string, mixed> $model @return array{keywords: string, filters: array<string, mixed>} */
    private function fromModel(array $model): array
    {
        $filters = [];
        foreach (['maker', 'series', 'genre', 'actress'] as $key) {
            $value = $model[$key] ?? null;
            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 100) {
                $filters[$key] = trim($value);
            }
        }
        $from = $model['year_from'] ?? null;
        $to = $model['year_to'] ?? null;
        if (is_numeric($from) && (int) $from >= 1950 && (int) $from <= 2100) {
            $filters['released_from'] = (int) $from . '-01-01';
        }
        if (is_numeric($to) && (int) $to >= 1950 && (int) $to <= 2100) {
            $filters['released_to'] = (int) $to . '-12-31';
        }
        foreach (['runtime_min', 'runtime_max'] as $key) {
            if (is_numeric($model[$key] ?? null) && (int) $model[$key] > 0 && (int) $model[$key] < 1000) {
                $filters[$key] = (int) $model[$key];
            }
        }
        $keywords = $model['keywords'] ?? '';

        return ['keywords' => is_string($keywords) ? trim(mb_substr($keywords, 0, 200)) : '', 'filters' => $filters];
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

    private function performerIn(string $text): ?string
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $candidates = [];
        for ($n = 3; $n >= 2; $n--) {
            $total = count($words);
            for ($i = 0; $i + $n <= $total; $i++) {
                $candidates[] = mb_strtolower(implode(' ', array_slice($words, $i, $n)));
            }
        }
        if ($candidates === []) {
            return null;
        }
        $name = DB::table('performers')->whereNull('canonical_performer_id')->whereIn(DB::raw('LOWER(name_romaji)'), $candidates)->orderByRaw('LENGTH(name_romaji) DESC')->value('name_romaji');

        return is_string($name) ? $name : null;
    }
}
