<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;
use RuntimeException;

final class UnmergePerformerIdentityCommand extends Command
{
    protected $signature = 'performers:identity-unmerge {event : The merge event to reverse}';

    protected $description = 'Restore the canonical mapping preceding an identity merge.';

    public function handle(PerformerIdentityService $identities): int
    {
        $argument = (string) $this->argument('event');
        if (! ctype_digit($argument)
            || filter_var($argument, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            $this->error('The merge event must be a positive integer ID.');

            return self::FAILURE;
        }
        try {
            $event = $identities->split((int) $argument);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info("Split recorded as event {$event->id}.");

        return self::SUCCESS;
    }
}
