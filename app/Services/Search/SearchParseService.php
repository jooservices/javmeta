<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Data\Search\SearchParseResult;
use App\Exceptions\DependencyUnavailableException;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use Illuminate\Support\Facades\Cache;

/** Turns a natural-language question into keywords and filters, with a short cache and telemetry about how it was understood. */
final class SearchParseService
{
    public function __construct(
        private readonly QueryInterpreter $interpreter,
        private readonly DependencyMonitor $dependencies,
        private readonly FilterExplainer $explainer,
    ) {}

    /** @throws DependencyUnavailableException when the database the interpreter reads from is down */
    public function parse(string $question, SearchParseTelemetry $telemetry): SearchParseResult
    {
        $question = trim($question);
        $startedAt = hrtime(true);
        $cacheMiss = false;

        try {
            // The cache may hold entries written by an older release, so the shape is checked, not assumed.
            /** @var array<string, mixed> $cached */
            $cached = Cache::remember(
                'javmeta:search:parse:' . hash('sha256', mb_strtolower($question)),
                max(0, (int) config('javmeta_ai.nl_cache_seconds', 60)),
                function () use ($question, &$cacheMiss): array {
                    $cacheMiss = true;
                    if (! $this->dependencies->isAvailable(Dependency::Postgres)) {
                        throw new DependencyUnavailableException(Dependency::Postgres);
                    }

                    $interpreted = $this->interpreter->interpretWithTelemetry($question);
                    $parsed = $interpreted['result'];

                    return [
                        'keywords' => $parsed['keywords'],
                        'filters' => $parsed['filters'],
                        'explanations' => $this->explainer->explain($parsed['filters']),
                        'by' => $parsed['by'],
                        '_search_parse_telemetry' => $interpreted['telemetry'],
                    ];
                },
            );

            $chat = is_array($cached['_search_parse_telemetry'] ?? null) ? $cached['_search_parse_telemetry'] : [];
            $telemetry->succeeded((string) ($chat['backend'] ?? $cached['by'] ?? ''), $cacheMiss, $chat);

            return new SearchParseResult((string) ($cached['keywords'] ?? ''), (array) ($cached['filters'] ?? []), (array) ($cached['explanations'] ?? []), (string) ($cached['by'] ?? 'rules'));
        } catch (DependencyUnavailableException $exception) {
            $telemetry->dependencyUnavailable();

            throw $exception;
        } finally {
            $telemetry->finished($startedAt);
        }
    }
}
