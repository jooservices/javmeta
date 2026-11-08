<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

final class LookupMovie extends HttpStep
{
    public function __construct(private readonly string $code, private readonly int $expectedStatus = 200, private readonly ?string $expectedDisplayCode = null) {}

    public function run(Context $context): Context
    {
        $credential = $this->credential($context);
        $result = $this->exchange($context, 'GET', '/api/v1/movies/' . rawurlencode($this->code), ['X-Api-Key' => $credential->plaintext]);
        $context = $result->context;
        $this->expectStatus($context, $result->response, $this->expectedStatus);

        if ($this->expectedStatus === 404) {
            if ($result->response->json('type') !== 'movie_not_found' || $result->response->json('data') !== null) {
                throw new StepFailure('Unknown movie lookup did not return a distinct not-found response.', $context);
            }

            return $context;
        }

        $actualCode = $result->response->json('data.code');
        if (! is_string($actualCode) || ($this->expectedDisplayCode !== null && $actualCode !== $this->expectedDisplayCode)) {
            throw new StepFailure('Movie lookup returned an unexpected display code.', $context);
        }

        return $context;
    }
}
