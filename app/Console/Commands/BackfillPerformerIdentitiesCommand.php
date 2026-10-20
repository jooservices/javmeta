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
        $result = $identities->backfill($dryRun);

        foreach ($result['merges'] as $merge) {
            $this->line(sprintf(
                '%s merge source %d into %d (%s)',
                $dryRun ? 'Would' : 'Did',
                $merge['from_performer_id'],
                $merge['to_performer_id'],
                $merge['reason'],
            ));
        }
        foreach ($result['reviews'] as $review) {
            $this->line(sprintf(
                'Review source %d against %d (%s, %.4f)',
                $review['source_performer_id'],
                $review['candidate_performer_id'],
                $review['reason'],
                $review['similarity'],
            ));
        }

        $this->line('Planned or completed merges: ' . count($result['merges']));
        $this->line('Ambiguous matches for review: ' . count($result['reviews']));

        return self::SUCCESS;
    }
}
