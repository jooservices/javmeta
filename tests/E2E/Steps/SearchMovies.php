<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class SearchMovies extends HttpStep
{
    /** @param array<string, scalar|null> $query @param list<string> $expectedCodes */
    public function __construct(
        private readonly array $query,
        private readonly array $expectedCodes = [],
        private readonly int $expectedStatus = 200,
        private readonly bool $exactCodes = false,
        private readonly bool $walkPagination = false,
    ) {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context);
        $first = $this->exchange($context, 'GET', '/api/v1/movies', ['X-Api-Key' => $credential->plaintext], $this->query);
        $context = $first->context;
        $this->expectStatus($context, $first->response, $this->expectedStatus);

        if ($this->expectedStatus !== 200) {
            return $context;
        }

        if (! $this->walkPagination) {
            $this->assertCodes($context, $first->response->json('data'), $this->expectedCodes, $this->exactCodes);

            return $context;
        }

        return $this->walkPages($context, $first->response);
    }

    /** @param list<string> $expected */
    private function assertCodes(Context $context, mixed $items, array $expected, bool $exact): void
    {
        if (! is_array($items)) {
            throw new StepFailure('Movie search did not return a result list.', $context);
        }

        $codes = array_values(array_map(static fn(mixed $item): string => is_array($item) ? (string) ($item['code'] ?? '') : '', $items));
        foreach ($expected as $code) {
            if (! in_array($code, $codes, true)) {
                throw new StepFailure('Movie search omitted an expected code.', $context);
            }
        }
        if ($exact && $codes !== $expected) {
            throw new StepFailure('Movie search returned an unexpected ordered result set.', $context);
        }

        if (isset($this->query['genre'])) {
            foreach ($items as $item) {
                $genres = is_array($item) ? (array) ($item['genres'] ?? []) : [];
                $labels = array_map(static fn(mixed $genre): string => strtolower(is_array($genre) ? (string) ($genre['label'] ?? $genre['name'] ?? '') : (string) $genre), $genres);
                if (! in_array(strtolower((string) $this->query['genre']), $labels, true)) {
                    throw new StepFailure('A search result did not satisfy its genre filter.', $context);
                }
            }
        }
    }

    private function walkPages(Context $context, \Illuminate\Http\Client\Response $firstResponse): Context
    {
        $firstItems = $firstResponse->json('data');
        if (! is_array($firstItems)) {
            throw new StepFailure('Movie search did not return a result list.', $context);
        }
        $firstCodes = array_values(array_map(static fn(array $item): string => (string) ($item['code'] ?? ''), $firstItems));

        $repeat = $this->exchange($context, 'GET', '/api/v1/movies', ['X-Api-Key' => $this->credential($context)->plaintext], $this->query);
        $context = $repeat->context;
        $this->expectStatus($context, $repeat->response, 200);
        $repeatCodes = array_values(array_map(static fn(array $item): string => (string) ($item['code'] ?? ''), (array) $repeat->response->json('data')));
        if ($firstCodes !== $repeatCodes) {
            throw new StepFailure('Repeated first-page searches were not stable.', $context);
        }

        $allCodes = $firstCodes;
        $meta = (array) $firstResponse->json('meta.pagination', []);
        $total = (int) ($meta['total'] ?? -1);
        $cursor = is_string($meta['next_cursor'] ?? null) ? $meta['next_cursor'] : null;
        $hasMore = ($meta['has_more'] ?? false) === true;
        $query = $this->query;

        while ($hasMore) {
            if ($cursor === null) {
                throw new StepFailure('A page with more results omitted its next cursor.', $context);
            }
            $query['cursor'] = $cursor;
            $page = $this->exchange($context, 'GET', '/api/v1/movies', ['X-Api-Key' => $this->credential($context)->plaintext], $query);
            $context = $page->context;
            $this->expectStatus($context, $page->response, 200);
            $items = $page->response->json('data');
            if (! is_array($items)) {
                throw new StepFailure('A movie search page did not return a result list.', $context);
            }
            $codes = array_values(array_map(static fn(array $item): string => (string) ($item['code'] ?? ''), $items));
            if (array_intersect($allCodes, $codes) !== []) {
                throw new StepFailure('Movie search pagination returned a duplicate code.', $context);
            }
            array_push($allCodes, ...$codes);
            $meta = (array) $page->response->json('meta.pagination', []);
            $cursor = is_string($meta['next_cursor'] ?? null) ? $meta['next_cursor'] : null;
            $hasMore = ($meta['has_more'] ?? false) === true;
        }

        if (count($allCodes) !== $total || $allCodes !== $this->expectedCodes) {
            throw new StepFailure('Movie search pagination skipped results or returned an incorrect total.', $context);
        }

        if ($cursor !== null) {
            $query['cursor'] = $cursor;
            $beyond = $this->exchange($context, 'GET', '/api/v1/movies', ['X-Api-Key' => $this->credential($context)->plaintext], $query);
            $context = $beyond->context;
            $this->expectStatus($context, $beyond->response, 200);
            if ($beyond->response->json('data') !== [] || (int) $beyond->response->json('meta.pagination.total') !== $total) {
                throw new StepFailure('A page beyond the result set was not empty or lost its total.', $context);
            }
        }

        return $context;
    }
}
