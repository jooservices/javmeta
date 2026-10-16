<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use Illuminate\Console\Command;
use Sentry\State\HubInterface;

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

        $eventId = $hub->captureMessage('Javmeta Sentry integration test event');
        if ($eventId === null) {
            $this->components->error('Sentry did not return an event ID.');

            return self::FAILURE;
        }

        $this->components->info('Sentry test event ID: ' . $eventId);

        return self::SUCCESS;
    }
}
