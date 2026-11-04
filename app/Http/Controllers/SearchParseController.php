<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\DependencyUnavailableException;
use App\Http\Requests\SearchParseRequest;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Search\FilterExplainer;
use App\Services\Search\QueryInterpreter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class SearchParseController extends BaseApiController
{
    public function __invoke(SearchParseRequest $request, QueryInterpreter $interpreter, DependencyMonitor $dependencies, FilterExplainer $explainer): JsonResponse
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
            $cached = Cache::remember($cacheKey, $ttl, function () use ($interpreter, $question, $dependencies, $explainer, &$cacheMiss): array {
                $cacheMiss = true;
                if (! $dependencies->isAvailable(Dependency::Postgres)) {
                    throw new DependencyUnavailableException(Dependency::Postgres);
                }

                $interpreted = $interpreter->interpretWithTelemetry($question);
                $parsed = $interpreted['result'];

                return [
                    'keywords' => $parsed['keywords'],
                    'filters' => $parsed['filters'],
                    'explanations' => $explainer->explain($parsed['filters']),
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
}
