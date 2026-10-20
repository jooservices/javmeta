<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;
use RuntimeException;

final class UndoPerformerSplitCommand extends Command
{
    protected $signature = 'performers:undo-split {split_event_id : The split event to undo}';

    protected $description = 'Restore a performer merge that was reversed by a split event.';

    public function handle(PerformerIdentityService $identities): int
    {
        try {
            $event = $identities->undoSplit((int) $this->argument('split_event_id'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Merge restored as event {$event->id}.");

        return self::SUCCESS;
    }
}
