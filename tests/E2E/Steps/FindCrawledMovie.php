<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

/** Finds the movie produced by the fixture crawl over REST: by code lookup, by code filter or by text search. */
final class FindCrawledMovie extends HttpStep
{
    public const LOOKUP = 'lookup';
    public const CODE = 'code';
    public const TEXT = 'text';

    public function __construct(private readonly string $mode = self::LOOKUP, private readonly int $expectedStatus = 200) {}

    public function run(Context $context): Context
    {
        $crawl = $context->crawl;
        if ($crawl === null) {
            throw new StepFailure('No fixture crawl is available to search for.', $context);
        }
        $headers = ['X-Api-Key' => $this->credential($context)->plaintext];

        $result = match ($this->mode) {
            self::LOOKUP => $this->exchange($context, 'GET', '/api/v1/movies/' . rawurlencode($crawl->movieCode), $headers),
            self::CODE => $this->exchange($context, 'GET', '/api/v1/movies', $headers, ['code' => $crawl->movieCode]),
            self::TEXT => $this->exchange($context, 'GET', '/api/v1/movies', $headers, ['q' => $crawl->movieTitle]),
            default => throw new StepFailure("Unknown movie search mode {$this->mode}.", $context),
        };
        $context = $result->context;
        $this->expectStatus($context, $result->response, $this->expectedStatus);

        if ($this->expectedStatus !== 200) {
            if ($result->response->json('data') !== null) {
                throw new StepFailure('A rejected movie search included catalog data.', $context);
            }

            return $context;
        }

        $data = $result->response->json('data');
        $codes = $this->mode === self::LOOKUP
            ? [is_array($data) ? (string) ($data['code'] ?? '') : '']
            : array_map(static fn(mixed $item): string => is_array($item) ? (string) ($item['code'] ?? '') : '', is_array($data) ? $data : []);
        if (! in_array($crawl->movieCode, $codes, true)) {
            throw new StepFailure("The crawled movie was not found by {$this->mode}.", $context);
        }

        return $context;
    }
}
