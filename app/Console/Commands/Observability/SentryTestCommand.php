<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Observability\Sentry\SentryContext;
use Illuminate\Console\Command;
use Sentry\State\HubInterface;
use Sentry\State\Scope;

final class SentryTestCommand extends Command
{
    protected $signature = 'javmeta:sentry-test';

    protected $description = 'Send a test event to the configured Sentry project.';

    public function handle(HubInterface $hub): int
    {
        if ($hub->getClient() === null) {
            $this->components->error('Sentry is disabled; no event was sent.');

            return self::FAILURE;
        }

        $eventId = null;
        $hub->withScope(function (Scope $scope) use ($hub, &$eventId): void {
            SentryContext::applyToScope($scope);
            $scope->setTag('test_event', 'true');
            $eventId = $hub->captureMessage('Javmeta Sentry integration test event');
        });

        if ($eventId === null) {
            $this->components->error('Sentry did not return an event ID.');

            return self::FAILURE;
        }

        $this->components->info('Sentry test event ID: ' . $eventId);

        return self::SUCCESS;
    }
}
