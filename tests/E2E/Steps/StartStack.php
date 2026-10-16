<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class StartStack extends HttpStep
{
    public function run(Context $context): Context
    {
        $result = $this->exchange($context, 'GET', '/api/health');
        $context = $result->context;
        $this->expectStatus($context, $result->response, 200);
        $body = $result->response->json();

        if (! is_array($body) || ($body['status'] ?? null) !== 'ok' || ($body['worker']['stale'] ?? true) !== false) {
            throw new StepFailure('The health endpoint did not report a green stack.', $context);
        }

        $sources = $body['sources'] ?? null;
        if (! is_array($sources) || $sources === []) {
            throw new StepFailure('The health endpoint did not report the crawled fixture source.', $context);
        }
        foreach ($sources as $source) {
            if (! is_array($source) || ! is_string($source['slug'] ?? null) || ! is_string($source['last_success_at'] ?? null)) {
                throw new StepFailure('The health endpoint omitted a source crawl status.', $context);
            }
        }

        foreach (['movies', 'titles', 'performers'] as $privateField) {
            if (array_key_exists($privateField, $body)) {
                throw new StepFailure('The public health response exposed catalog data.', $context);
            }
        }

        return $context;
    }
}
