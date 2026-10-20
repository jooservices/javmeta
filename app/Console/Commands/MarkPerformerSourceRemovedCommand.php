<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;

final class MarkPerformerSourceRemovedCommand extends Command
{
    protected $signature = 'performers:source-removed {source_slug} {external_id}';

    protected $description = 'Mark a source performer record removed while retaining its canonical performer.';

    public function handle(PerformerIdentityService $identities): int
    {
        $removed = $identities->markSourceRemoved(
            (string) $this->argument('source_slug'),
            (string) $this->argument('external_id'),
        );

        if (! $removed) {
            $this->error('The source performer record was not found.');

            return self::FAILURE;
        }

        $this->info('Source record marked removed. The canonical performer was retained.');

        return self::SUCCESS;
    }
}
