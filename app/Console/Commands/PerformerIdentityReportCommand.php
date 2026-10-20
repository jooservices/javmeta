<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PerformerIdentityReportCommand extends Command
{
    protected $signature = 'performers:identity-report {--status=open : Review status to list}';

    protected $description = 'List ambiguous performer identity candidates for manual review.';

    public function handle(): int
    {
        $rows = DB::table('performer_identity_reviews as reviews')
            ->join('performers as source', 'source.id', '=', 'reviews.source_performer_id')
            ->join('performers as candidate', 'candidate.id', '=', 'reviews.candidate_performer_id')
            ->where('reviews.status', (string) $this->option('status'))
            ->orderBy('reviews.id')
            ->get([
                'reviews.id', 'source.id as source_id', 'source.name_romaji as source_name',
                'candidate.id as candidate_id', 'candidate.name_romaji as candidate_name',
                'reviews.reason', 'reviews.similarity',
            ]);

        $this->table(
            ['Review', 'Source performer', 'Source name', 'Candidate', 'Candidate name', 'Reason', 'Similarity'],
            $rows->map(static fn(object $row): array => [
                $row->id, $row->source_id, $row->source_name, $row->candidate_id,
                $row->candidate_name, $row->reason, number_format((float) $row->similarity, 4),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
