<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\CrawlQueue;
use App\Models\MovieCode;
use App\Observability\ObservabilityEmitter;
use Illuminate\Console\Command;

/**
 * Puts the detail crawl of an incomplete movie back in the queue once its last crawl is older than the configured
 * number of days (default 7), so a page that was missing information is read again.
 */
final class RecrawlIncompleteCommand extends Command
{
    protected $signature = 'crawler:recrawl-incomplete {--days= : Days since the last crawl (default JAVMETA_RECRAWL_DAYS)} {--limit= : Rows per run (default JAVMETA_RECRAWL_BATCH)}';

    protected $description = 'Queue the detail page of incomplete movies again after a number of days.';

    public function handle(ObservabilityEmitter $observability): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('javmeta_sources.defaults.recrawl.incomplete_after_days', 7)));
        $limit = max(1, (int) ($this->option('limit') ?? config('javmeta_sources.defaults.recrawl.batch', 200)));
        $minTier = (int) config('javmeta_sources.defaults.recrawl.min_tier', 3);

        $queued = 0;
        MovieCode::query()
            ->select('movie_codes.*')
            ->join('movies', 'movies.id', '=', 'movie_codes.movie_id')
            ->where('movies.completeness_tier', '<', $minTier)
            ->where('movie_codes.crawled_at', '<', now()->subDays($days))
            ->whereNotNull('movie_codes.source_url')
            ->orderBy('movie_codes.crawled_at')
            ->limit($limit * 3)
            ->get()
            ->each(function (MovieCode $code) use (&$queued, $limit): void {
                if ($queued >= $limit) {
                    return;
                }

                $updated = CrawlQueue::query()
                    ->where('source_slug', $code->source_slug)
                    ->where('url', $code->source_url)
                    ->where('kind', CrawlQueue::KIND_DETAIL)
                    ->where('status', CrawlQueue::STATUS_DONE)
                    ->update([
                        'status' => CrawlQueue::STATUS_PENDING,
                        'attempts' => 0,
                        'next_attempt_at' => now(),
                        'claimed_at' => null,
                        'started_at' => null,
                        'locked_by' => null,
                        'last_error' => null,
                        'error_code' => null,
                        'last_failed_at' => null,
                    ]);

                $queued += $updated;
            });

        if ($queued > 0) {
            $this->components->info("Queued {$queued} incomplete movie page(s) for a new crawl.");
            $observability->emitOps('recrawl_incomplete', ['queued' => $queued, 'days' => $days]);
        }

        return self::SUCCESS;
    }
}
