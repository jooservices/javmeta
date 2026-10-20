<?php

declare(strict_types=1);

namespace Tests\E2E\Steps;

use Tests\E2E\Context;
use Tests\E2E\Support\HttpStep;
use Tests\E2E\Support\StepFailure;

/** Checks how the public health response reports the fixture source: failing (down) or crawling again (recovered). */
final class CheckSourceHealth extends HttpStep
{
    public const DOWN = 'down';
    public const RECOVERED = 'recovered';

    public function __construct(private readonly string $state) {}

    public function run(Context $context): Context
    {
        if ($context->fixture === null) {
            throw new StepFailure('No fixture source was seeded to check.', $context);
        }

        $result = $this->exchange($context, 'GET', '/api/health');
        $context = $result->context;
        $this->expectStatus($context, $result->response, 200);

        $row = null;
        foreach ((array) $result->response->json('sources', []) as $source) {
            if (is_array($source) && ($source['slug'] ?? null) === $context->fixture->slug) {
                $row = $source;
            }
        }
        if ($row === null) {
            throw new StepFailure('The health endpoint omitted the fixture source.', $context);
        }

        $lastSuccess = $this->timestamp($row['last_success_at'] ?? null);
        $lastError = $this->timestamp($row['last_error_at'] ?? null);

        $healthy = match ($this->state) {
            self::DOWN => $lastError !== null && $lastSuccess === null,
            self::RECOVERED => $lastSuccess !== null && ($lastError === null || $lastSuccess >= $lastError),
            default => false,
        };
        if (! $healthy) {
            throw new StepFailure("The health endpoint did not report the source as {$this->state}.", $context);
        }

        return $context;
    }

    private function timestamp(mixed $value): ?int
    {
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : $time;
    }
}
