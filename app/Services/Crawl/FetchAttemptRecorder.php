<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawler\CrawlerxFetchResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FetchAttemptRecorder
{
    public function record(CrawlQueue $queue, CrawlerxFetchResult $result): void
    {
        $rows = [];
        foreach ($result->attempts as $attempt) {
            // Field presence is the compatibility gate for pre-diagnostics CrawlerX releases.
            if (! isset($attempt['started_at'], $attempt['duration_ms'], $attempt['outcome'])) {
                continue;
            }
            $rows[] = [
                'created_at' => now(),
                'source_slug' => $queue->source_slug,
                'crawl_queue_id' => $queue->id,
                'url_hash' => hash('sha256', $queue->url),
                'method' => mb_substr((string) $attempt['method'], 0, 64),
                'started_at' => CarbonImmutable::parse((string) $attempt['started_at'])->utc(),
                'duration_ms' => max(0, (int) $attempt['duration_ms']),
                'http_status' => $attempt['http_status'] ?? null,
                'cf_mitigated' => isset($attempt['cf_mitigated']) ? mb_substr((string) $attempt['cf_mitigated'], 0, 255) : null,
                'outcome' => $attempt['outcome'],
                'error_code' => isset($attempt['error_code']) ? mb_substr((string) $attempt['error_code'], 0, 64) : null,
                'error' => $this->sanitize($attempt['error'] ?? null),
            ];
        }
        if ($rows !== []) {
            DB::table('crawl_fetch_attempts')->insert($rows);
        }
    }

    private function sanitize(?string $error): ?string
    {
        if ($error === null) {
            return null;
        }

        $error = preg_replace('~https?://[^\s<>]+~i', '[url]', strip_tags($error)) ?? 'fetch error';

        return mb_substr($error, 0, 500);
    }
}
