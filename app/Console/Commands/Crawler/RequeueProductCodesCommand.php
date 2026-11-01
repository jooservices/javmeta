<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Enums\CrawlErrorCode;
use App\Models\CrawlQueue;
use Illuminate\Console\Command;

/** Scoped recovery after the product-code normalization fix. */
final class RequeueProductCodesCommand extends Command
{
    protected $signature = 'crawler:requeue-product-codes {--dry-run : Report matching counts without changing rows}';

    protected $description = 'Retry failed product-code rows for javbtc, ffjav, tokyohot and 141jav.';

    public function handle(): int
    {
        foreach (['javbtc', 'ffjav', 'tokyohot', '141jav'] as $slug) {
            $query = CrawlQueue::query()
                ->where('source_slug', $slug)
                ->where('kind', CrawlQueue::KIND_DETAIL)
                ->where('status', CrawlQueue::STATUS_FAILED)
                ->whereIn('error_code', [CrawlErrorCode::ParseFailed->value, CrawlErrorCode::NormalizationFailed->value]);

            $count = $this->option('dry-run') ? $query->count() : $query->update([
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
            $this->line($slug . ': ' . $count . ($this->option('dry-run') ? ' would requeue' : ' requeued'));
        }

        return self::SUCCESS;
    }
}
