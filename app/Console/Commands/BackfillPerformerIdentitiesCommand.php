<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;

final class BackfillPerformerIdentitiesCommand extends Command
{
    protected $signature = 'performers:backfill-identities {--dry-run : List planned merges and reviews without changing data}';

    protected $description = 'Link source performer records to canonical performers using strong identity signals.';

    public function handle(PerformerIdentityService $identities): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $result = $identities->streamBackfill($dryRun, function (string $kind, array $row) use ($dryRun): void {
            if ($kind === 'merge') {
                $this->line(sprintf(
                    '%s merge source %d into %d (%s)',
                    $dryRun ? 'Would' : 'Did',
                    $row['from_performer_id'],
                    $row['to_performer_id'],
                    $row['reason'],
                ));

                return;
            }
            $this->line(sprintf(
                'Review source %d against %d (%s, %.4f)',
                $row['source_performer_id'],
                $row['candidate_performer_id'],
                $row['reason'],
                $row['similarity'],
            ));
        });

        $this->line('Planned or completed merges: ' . $result['merges']);
        $this->line('Ambiguous matches for review: ' . $result['reviews']);

        return self::SUCCESS;
    }
}
