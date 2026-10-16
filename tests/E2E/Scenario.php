<?php

declare(strict_types=1);

namespace Tests\E2E;

use ReflectionClass;
use Throwable;
use Tests\E2E\Support\Redactor;
use Tests\E2E\Support\ScenarioFailure;
use Tests\E2E\Support\StepFailure;

abstract class Scenario
{
    public function __construct(private readonly Redactor $redactor = new Redactor()) {}

    abstract protected function name(): string;

    /** @return list<Step> */
    abstract protected function steps(): array;

    public function run(Context $context): Context
    {
        $scenarioName = $this->name();

        foreach ($this->steps() as $step) {
            try {
                $context = $step->run($context);
            } catch (Throwable $exception) {
                $failedContext = $exception instanceof StepFailure ? $exception->context : $context;
                $stepName = (new ReflectionClass($step))->getShortName();
                $message = $this->redactor->redactText($exception->getMessage(), $failedContext->secrets());
                $exchange = $this->redactor->formatExchange($failedContext->lastRequest, $failedContext->lastResponse, $failedContext->secrets());

                throw new ScenarioFailure(
                    "Scenario {$scenarioName} failed at step {$stepName}: {$message}\nHTTP exchange:\n{$exchange}",
                    previous: $exception,
                );
            }
        }

        return $context;
    }
}
