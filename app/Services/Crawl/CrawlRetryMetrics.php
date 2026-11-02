<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use Illuminate\Support\Facades\DB;

final class CrawlRetryMetrics
{
    public function increment(string $source, int $round, string $outcome, string $egress): void
    {
        $key = [
            'source_slug' => $source,
            'round' => $round,
            'outcome' => $outcome,
            'egress_label' => $egress,
        ];
        DB::table('crawl_retry_metrics')->insertOrIgnore($key + ['value' => 0]);
        DB::table('crawl_retry_metrics')->where($key)->increment('value');
    }
}
