<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Crawl\SourceCoverage;
use Illuminate\Console\Command;

final class CoverageReportCommand extends Command
{
    protected $signature = 'crawler:coverage-report {--source= : Only this source}';

    protected $description = 'Per enabled source: listing pages found and crawled, detail rows pending and done, the age of the last new item, and whether the figures show the source exhausted or not fully crawled.';

    public function handle(SourceCoverage $coverage): int
    {
        $requested = $this->option('source');
        $source = is_string($requested) && $requested !== '' ? $requested : null;
        $rows = $coverage->report($source);
        if ($rows === []) {
            if ($source !== null) {
                $this->error("Unknown or disabled source [{$source}].");

                return self::FAILURE;
            }
            $this->info('No enabled source.');

            return self::SUCCESS;
        }

        $this->table(
            ['Source', 'Listing pages found', 'Listing pages crawled', 'Detail pending', 'Detail done', 'Last new item', 'Verdict'],
            array_map(static fn(array $row): array => [
                $row['source_slug'],
                $row['listing_discovered'],
                $row['listing_crawled'],
                $row['detail_pending'],
                $row['detail_done'],
                $row['last_new_item_age_seconds'] === null ? 'none in the statistics' : intdiv($row['last_new_item_age_seconds'], 3600) . ' h ago',
                $row['verdict'],
            ], $rows),
        );

        return self::SUCCESS;
    }
}
