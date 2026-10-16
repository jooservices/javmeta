<?php

declare(strict_types=1);

namespace App\Observability\Sentry;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;

final class CrawlFailureReporter
{
    public function __construct(private readonly HubInterface $hub) {}

    public function report(Throwable $exception, string $source, string $errorCode): void
    {
        if ($this->hub->getClient() === null) {
            return;
        }

        $this->hub->withScope(function (Scope $scope) use ($exception, $source, $errorCode): void {
            SentryContext::applyToScope($scope);
            $scope->setTag('source', $source);
            $scope->setTag('error_code', $errorCode);
            $scope->setFingerprint([$errorCode, $source]);

            $this->hub->captureException($exception);
        });
    }
}
