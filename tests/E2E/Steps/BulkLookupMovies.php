<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class BulkLookupMovies extends HttpStep
{
    /** @param list<string> $codes @param array<string, string> $expectedStatuses */
    public function __construct(private readonly array $codes, private readonly int $expectedStatus = 200, private readonly array $expectedStatuses = []) {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context);
        $result = $this->exchange($context, 'POST', '/api/v1/movies:bulk', ['X-Api-Key' => $credential->plaintext], ['codes' => $this->codes]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, $this->expectedStatus);

        if ($this->expectedStatus === 413) {
            if ($result->response->json('type') !== 'bulk_limit_exceeded' || $result->response->json('data') !== null) {
                throw new StepFailure('An oversized bulk request was partially processed.', $context);
            }

            return $context;
        }

        $items = $result->response->json('data');
        if (! is_array($items)) {
            throw new StepFailure('Bulk lookup did not return a result list.', $context);
        }

        $actual = [];
        foreach ($items as $item) {
            if (is_array($item) && is_string($item['code'] ?? null) && is_string($item['status'] ?? null)) {
                $actual[$item['code']] = $item['status'];
            }
        }
        if ($actual !== $this->expectedStatuses) {
            throw new StepFailure('Bulk lookup returned unexpected per-code statuses.', $context);
        }

        return $context;
    }
}
