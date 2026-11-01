<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Identity\PerformerIdentityService;
use Illuminate\Console\Command;

final class BackfillPerformerIdentitiesCommand extends Command
{
    protected $signature = 'performers:backfill-identities {--dry-run : List planned merges and reviews without changing data}
        {--rule= : Restrict matching to co_credit}
        {--limit= : Maximum merges applied in this slice}';

    protected $description = 'Link source performer records to canonical performers using strong identity signals.';

    public function handle(PerformerIdentityService $identities): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $rule = $this->option('rule');
        $limit = $this->option('limit');
        if (($rule !== null && $rule !== 'co_credit')
            || ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1))) {
            $this->error('Use --rule=co_credit and a positive integer --limit.');

            return self::FAILURE;
        }
        $sampleCount = 0;
        $result = $identities->streamBackfill($dryRun, function (string $kind, array $row) use ($dryRun, &$sampleCount): void {
            if ($kind === 'merge') {
                if ($row['reason'] === 'co_credit' && $sampleCount < 20) {
                    $sampleCount++;
                    $this->line(sprintf(
                        'Co-credit sample: %s [%s] + %s [%s] — %s',
                        $row['name'],
                        $row['source'],
                        $row['candidate_name'],
                        $row['candidate_source'],
                        $row['movie_code'],
                    ));
                }
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
        }, $rule, $limit === null ? null : (int) $limit);

        $this->line('Planned or completed merges: ' . $result['merges']);
        $this->line('Ambiguous matches for review: ' . $result['reviews']);

        foreach (['name_and_birth_date', 'cross_link', 'co_credit', 'reviews_closed', 'conflicts_kept'] as $counter) {
            $this->line($counter . ': ' . $result[$counter]);
        }

        return self::SUCCESS;
    }
}
