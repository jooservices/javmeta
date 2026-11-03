<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Observability\MetricStreams;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Prints cumulative rejected normalization values using the exported metric labels. */
final class NormalizationReportCommand extends Command
{
    protected $signature = 'performers:normalization-report';

    protected $description = 'Report cumulative performer normalization rejections by field and source.';

    public function handle(): int
    {
        $rows = DB::table('normalization_rejections')
            ->orderBy('entity')->orderBy('field')->orderBy('source')
            ->get(['entity', 'field', 'source', 'rejected_total']);

        if ($rows->isEmpty()) {
            $this->line('No rejected values have been recorded.');

            return self::SUCCESS;
        }

        $this->line(MetricStreams::NAMES['normalize_rejected_total'] . '{entity,field,source}');
        foreach ($rows as $row) {
            $this->line(sprintf(
                '%s{entity="%s",field="%s",source="%s"} %d',
                MetricStreams::NAMES['normalize_rejected_total'],
                $row->entity,
                $row->field,
                $row->source,
                (int) $row->rejected_total,
            ));
        }

        return self::SUCCESS;
    }
}
