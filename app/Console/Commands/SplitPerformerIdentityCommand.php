<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;
use RuntimeException;

final class SplitPerformerIdentityCommand extends Command
{
    protected $signature = 'performers:split {merge_event_id : The merge event to reverse}';

    protected $description = 'Split a merged performer identity using its audit event.';

    public function handle(PerformerIdentityService $identities): int
    {
        try {
            $event = $identities->split((int) $this->argument('merge_event_id'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Split recorded as event {$event->id}.");

        return self::SUCCESS;
    }
}
