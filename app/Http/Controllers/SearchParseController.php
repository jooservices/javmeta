<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\DependencyUnavailableException;
use App\Http\Requests\SearchParseRequest;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Search\QueryInterpreter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class SearchParseController extends BaseApiController
{
    public function __invoke(SearchParseRequest $request, QueryInterpreter $interpreter, DependencyMonitor $dependencies): JsonResponse
    {
        $question = trim((string) $request->validated('q'));
        $cacheKey = 'javmeta:search:parse:' . hash('sha256', mb_strtolower($question));
        $ttl = max(0, (int) config('javmeta_ai.nl_cache_seconds', 60));
        $startedAt = hrtime(true);
        $cacheMiss = false;
        $telemetry = [
            'parse_outcome' => 'failed',
            'parse_cache_status' => 'miss',
            'parse_backend' => 'none',
            'parse_fallback_reason' => 'operation_failed',
            'parse_duration_ms' => 0,
            'ollama_chat_outcome' => 'not_attempted',
            'ollama_chat_duration_ms' => null,
            'ollama_chat_http_status' => null,
        ];

        try {
            $cached = Cache::remember($cacheKey, $ttl, function () use ($interpreter, $question, $dependencies, &$cacheMiss): array {
                $cacheMiss = true;
                if (! $dependencies->isAvailable(Dependency::Postgres)) {
                    throw new DependencyUnavailableException(Dependency::Postgres);
                }

                $interpreted = $interpreter->interpretWithTelemetry($question);
                $parsed = $interpreted['result'];

                return [
                    'keywords' => $parsed['keywords'],
                    'filters' => $parsed['filters'],
                    'explanations' => $this->explanations($parsed['filters']),
                    'by' => $parsed['by'],
                    '_search_parse_telemetry' => $interpreted['telemetry'],
                ];
            });

            $parseTelemetry = $this->cacheTelemetry($cached);
            unset($cached['_search_parse_telemetry']);
            $backend = $parseTelemetry['backend'] ?? $cached['by'];

            $telemetry = [
                'parse_outcome' => 'success',
                'parse_cache_status' => $cacheMiss ? 'miss' : 'hit',
                'parse_backend' => in_array($backend, ['ollama', 'rules'], true) ? $backend : 'rules',
                'parse_fallback_reason' => is_string($parseTelemetry['fallback_reason'] ?? null)
                    ? $parseTelemetry['fallback_reason']
                    : null,
                'parse_duration_ms' => 0,
                'ollama_chat_outcome' => $cacheMiss
                    ? (string) ($parseTelemetry['ollama_chat_outcome'] ?? 'not_attempted')
                    : 'cache_hit',
                'ollama_chat_duration_ms' => $cacheMiss && is_int($parseTelemetry['ollama_chat_duration_ms'] ?? null)
                    ? $parseTelemetry['ollama_chat_duration_ms']
                    : null,
                'ollama_chat_http_status' => $cacheMiss && is_int($parseTelemetry['ollama_chat_http_status'] ?? null)
                    ? $parseTelemetry['ollama_chat_http_status']
                    : null,
            ];

            return $this->respondWithData(data: $cached, message: 'Natural-language query parsed successfully.');
        } catch (DependencyUnavailableException $exception) {
            $telemetry['parse_fallback_reason'] = 'dependency_unavailable';
            throw $exception;
        } finally {
            $telemetry['parse_duration_ms'] = max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
            request()->attributes->set('javmeta.search_parse.telemetry', $telemetry);
        }
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>
     */
    private function cacheTelemetry(array $cached): array
    {
        $telemetry = $cached['_search_parse_telemetry'] ?? [];

        return is_array($telemetry) ? $telemetry : [];
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
