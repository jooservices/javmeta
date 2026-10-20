<?php

declare(strict_types=1);

namespace App\Observability\Sentry;

use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;
use WeakMap;

final class CrawlFailureReporter
{
    /** @var WeakMap<Throwable, bool>|null */
    private static ?WeakMap $reportedExceptions = null;

    public function __construct(private readonly HubInterface $hub) {}

    public static function wasReported(Throwable $exception): bool
    {
        return self::$reportedExceptions !== null && isset(self::$reportedExceptions[$exception]);
    }

    public function report(Throwable $exception, string $source, string $crawlKind, string $errorCode): void
    {
        if ($this->hub->getClient() === null) {
            return;
        }

        $this->hub->withScope(function (Scope $scope) use ($exception, $source, $crawlKind, $errorCode): void {
            SentryContext::applyToScope($scope);
            $scope->setTag('source', $source);
            $scope->setTag('crawl_kind', $crawlKind);
            $scope->setTag('error_code', $errorCode);
            $scope->setFingerprint([$errorCode, $source]);

            $this->hub->captureException($exception);
        });

        self::$reportedExceptions ??= new WeakMap();
        self::$reportedExceptions[$exception] = true;
    }
}
