<?php

declare(strict_types=1);

namespace App\Services\Archive;

use App\Observability\Sentry\SentryContext;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Sentry\State\HubInterface;
use Sentry\State\Scope;
use Throwable;

final class MongoArchiveFailureReporter
{
    public function __construct(private readonly HubInterface $hub) {}

    public function report(Throwable $exception, string $siteSlug, string $entityType): void
    {
        $context = [
            'error_code' => 'mongo_archive_write_failed',
            'entity_type' => $entityType,
            'site_slug' => $siteSlug,
            'exception_type' => $exception::class,
        ];
        Log::error('Mongo archive write failed.', $context);

        if ($this->hub->getClient() === null) {
            return;
        }

        try {
            $this->hub->withScope(function (Scope $scope) use ($context): void {
                SentryContext::applyToScope($scope);
                $scope->setTag('source', (string) $context['site_slug']);
                $scope->setTag('error_code', (string) $context['error_code']);
                $scope->setExtra('entity_type', $context['entity_type']);
                $scope->setExtra('exception_type', $context['exception_type']);

                // Avoid sending a driver message that could contain connection details.
                $this->hub->captureException(new RuntimeException('Mongo archive write failed.'));
            });
        } catch (Throwable $reportingException) {
            Log::warning('Mongo archive failure could not be sent to Sentry.', [
                'error_code' => 'mongo_archive_sentry_report_failed',
                'exception_type' => $reportingException::class,
            ]);
        }
    }
}
