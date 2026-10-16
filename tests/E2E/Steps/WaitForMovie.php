<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class WaitForMovie extends HttpStep
{
    public function __construct(private readonly int $attempts = 8, private readonly int $delayMilliseconds = 100) {}

    public function run(Context $context): Context
    {
        $crawl = $context->crawl;
        if ($crawl === null) {
            throw new StepFailure('No fixture crawl is available to wait for.', $context);
        }
        $credential = $this->credential($context);

        for ($attempt = 0; $attempt < $this->attempts; $attempt++) {
            $result = $this->exchange($context, 'GET', '/api/v1/movies/' . rawurlencode($crawl->movieCode), ['X-Api-Key' => $credential->plaintext]);
            $context = $result->context;
            if ($result->response->status() === 200) {
                if ($result->response->json('data.code') !== $crawl->movieCode) {
                    throw new StepFailure('The crawled movie lookup returned an unexpected code.', $context);
                }

                return $context;
            }
            if ($result->response->status() !== 404) {
                throw new StepFailure('The crawled movie lookup returned an unexpected HTTP status.', $context);
            }
            usleep($this->delayMilliseconds * 1000);
        }

        throw new StepFailure('The fixture-crawled movie did not become available before the wait expired.', $context);
    }
}
