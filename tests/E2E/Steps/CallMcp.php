<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class CallMcp extends HttpStep
{
    /** @param array<string, mixed> $arguments @param array<string, mixed> $expected */
    public function __construct(private readonly string $tool, private readonly array $arguments = [], private readonly array $expected = []) {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context, 'mcp');
        $result = $this->exchange($context, 'POST', '/api/v1/mcp', ['X-Api-Key' => $credential->plaintext], [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $this->tool, 'arguments' => $this->arguments],
        ]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, 200);
        $text = $result->response->json('result.content.0.text');
        $payload = is_string($text) ? json_decode($text, true) : null;

        if (! is_array($payload) || $result->response->json('result.isError') === true) {
            throw new StepFailure("MCP tool {$this->tool} returned an error.", $context);
        }
        $this->assertToolPayload($context, $payload);
        foreach ($this->expected as $path => $expected) {
            if (data_get($payload, (string) $path) !== $expected) {
                throw new StepFailure("MCP tool {$this->tool} returned unexpected data.", $context);
            }
        }

        return $context;
    }

    /** @param array<string, mixed> $payload */
    private function assertToolPayload(Context $context, array $payload): void
    {
        $valid = match ($this->tool) {
            'get_movie' => is_string($payload['code'] ?? null),
            'search' => is_string($payload['query'] ?? null) && is_array($payload['items'] ?? null),
            'get_counts' => is_int($payload['movies'] ?? null) && is_int($payload['performers'] ?? null),
            'crawl_status' => is_array($payload['sources'] ?? null) && is_array($payload['queue'] ?? null),
            'system_status' => is_string($payload['status'] ?? null) && is_array($payload['services'] ?? null),
            default => true,
        };

        if (! $valid) {
            throw new StepFailure("MCP tool {$this->tool} returned an invalid payload shape.", $context);
        }
    }
}
