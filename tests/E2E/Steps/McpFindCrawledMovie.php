<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

/** Finds the movie produced by the fixture crawl through an MCP tool call (get_movie or lookup_movies). */
final class McpFindCrawledMovie extends HttpStep
{
    public function __construct(private readonly string $tool = 'get_movie', private readonly int $expectedStatus = 200) {}

    public function run(Context $context): Context
    {
        $crawl = $context->crawl;
        if ($crawl === null) {
            throw new StepFailure('No fixture crawl is available to search for.', $context);
        }

        $arguments = match ($this->tool) {
            'get_movie', 'lookup_movies' => ['code' => $crawl->movieCode],
            default => throw new StepFailure("Unsupported MCP tool {$this->tool}.", $context),
        };
        $result = $this->exchange($context, 'POST', '/api/v1/mcp', ['X-Api-Key' => $this->credential($context, 'mcp')->plaintext], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $this->tool, 'arguments' => $arguments],
        ]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, $this->expectedStatus);

        if ($this->expectedStatus !== 200) {
            if ($result->response->json('result') !== null) {
                throw new StepFailure('A rejected MCP call included a tool result.', $context);
            }

            return $context;
        }

        $text = $result->response->json('result.content.0.text');
        $payload = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($payload) || $result->response->json('result.isError') === true) {
            throw new StepFailure("MCP tool {$this->tool} returned an error.", $context);
        }

        $codes = $this->tool === 'get_movie'
            ? [(string) ($payload['code'] ?? '')]
            : array_map(static fn(mixed $item): string => is_array($item) ? (string) ($item['code'] ?? '') : '', (array) ($payload['items'] ?? $payload['data'] ?? []));
        if (! in_array($crawl->movieCode, $codes, true)) {
            throw new StepFailure("MCP tool {$this->tool} did not return the crawled movie.", $context);
        }

        return $context;
    }
}
