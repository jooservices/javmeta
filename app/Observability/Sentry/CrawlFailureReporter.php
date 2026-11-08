<?php

declare(strict_types=1);

namespace App\Observability\Sentry;

use App\Enums\CrawlErrorCode;
use Illuminate\Queue\TimeoutExceededException;
use Sentry\Severity;
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
            $ssrfContext = self::ssrfContextFromMessage($exception->getMessage());
            if ($ssrfContext !== []) {
                $scope->setContext('ssrf', $ssrfContext);
                if (isset($ssrfContext['reason'])) {
                    $scope->setTag('ssrf_reason', $ssrfContext['reason']);
                }
            }
            $expectedSourceOutcome = in_array(CrawlErrorCode::tryFrom($errorCode), [CrawlErrorCode::Blocked, CrawlErrorCode::Network, CrawlErrorCode::Timeout], true)
                && ! ($errorCode === CrawlErrorCode::Timeout->value && $exception instanceof TimeoutExceededException);
            if ($expectedSourceOutcome) {
                $scope->setLevel(Severity::warning());
            }

            $this->hub->captureException($exception);
        });

        self::$reportedExceptions ??= new WeakMap();
        self::$reportedExceptions[$exception] = true;
    }

    /** @return array{reason?: string, address?: string} */
    private static function ssrfContextFromMessage(string $message): array
    {
        if (! str_contains($message, 'SSRF policy') && ! str_contains($message, 'ssrf_blocked')) {
            return [];
        }

        $context = [];
        if (preg_match('/\breason=([a-z0-9_]+)/i', $message, $reasonMatch) === 1) {
            $context['reason'] = $reasonMatch[1];
        }
        if (preg_match('/\baddress=([0-9a-fA-F:.]+)/', $message, $addressMatch) === 1) {
            $context['address'] = $addressMatch[1];
        }

        return $context;
    }
}
