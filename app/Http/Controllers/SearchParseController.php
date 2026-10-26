<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SearchParseRequest;
use App\Services\Search\QueryInterpreter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class SearchParseController extends BaseApiController
{
    public function __invoke(SearchParseRequest $request, QueryInterpreter $interpreter): JsonResponse
    {
        $question = trim((string) $request->validated('q'));
        $cacheKey = 'javmeta:search:parse:' . hash('sha256', mb_strtolower($question));
        $ttl = max(0, (int) config('javmeta_ai.nl_cache_seconds', 60));

        $data = Cache::remember($cacheKey, $ttl, function () use ($interpreter, $question): array {
            $parsed = $interpreter->interpret($question);

            return [
                'keywords' => $parsed['keywords'],
                'filters' => $parsed['filters'],
                'explanations' => $this->explanations($parsed['filters']),
                'by' => $parsed['by'],
            ];
        });

        return $this->respondWithData(data: $data, message: 'Natural-language query parsed successfully.');
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, string>
     */
    private function explanations(array $filters): array
    {
        $explanations = [];
        foreach ($filters as $field => $value) {
            $label = match ($field) {
                'maker' => 'Maker',
                'series' => 'Series',
                'genre' => 'Genre',
                'actress' => 'Performer',
                'blood_type' => 'Blood type',
                'location' => 'Location',
                'cup' => 'Cup size',
                'released_from' => 'Released on or after',
                'released_to' => 'Released on or before',
                'runtime_min' => 'Runtime at least (minutes)',
                'runtime_max' => 'Runtime at most (minutes)',
                'age_min' => 'Age at least',
                'age_max' => 'Age at most',
                'height_min' => 'Height at least (cm)',
                'height_max' => 'Height at most (cm)',
                'code' => 'Movie code',
                default => ucfirst(str_replace('_', ' ', (string) $field)),
            };
            $explanations[$field] = $label . ': ' . (is_scalar($value) ? (string) $value : '');
        }

        return $explanations;
    }
}
