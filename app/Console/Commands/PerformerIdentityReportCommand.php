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
        // Rows are paged by id and printed one by one, so memory does not grow with the number of reviews.
        $this->line(implode("\t", [
            'Review', 'Source performer', 'Source name', 'Candidate', 'Candidate name', 'Reason', 'Similarity',
        ]));

        $rows = DB::table('performer_identity_reviews as reviews')
            ->join('performers as source', 'source.id', '=', 'reviews.source_performer_id')
            ->join('performers as candidate', 'candidate.id', '=', 'reviews.candidate_performer_id')
            ->where('reviews.status', (string) $this->option('status'))
            ->select([
                'reviews.id', 'source.id as source_id', 'source.name_romaji as source_name',
                'candidate.id as candidate_id', 'candidate.name_romaji as candidate_name',
                'reviews.reason', 'reviews.similarity',
            ])
            ->lazyById(200, 'reviews.id', 'id');

        foreach ($rows as $row) {
            $this->line(implode("\t", [
                $row->id, $row->source_id, $row->source_name, $row->candidate_id,
                $row->candidate_name, $row->reason, number_format((float) $row->similarity, 4),
            ]));
        }

        return self::SUCCESS;
    }
}
