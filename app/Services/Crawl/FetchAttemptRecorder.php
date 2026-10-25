<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawler\CrawlerxFetchResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class FetchAttemptRecorder
{
    public function record(CrawlQueue $queue, CrawlerxFetchResult $result): void
    {
        try {
            $this->persist($queue, $result);
        } catch (Throwable $exception) {
            // Diagnostics must not prevent the crawl row from reaching its normal outcome.
            Log::warning('Failed to record fetch attempts.', ['exception' => $exception::class, 'queue_id' => $queue->id]);
        }
    }

    private function persist(CrawlQueue $queue, CrawlerxFetchResult $result): void
    {
        $rows = [];
        foreach ($result->attempts as $attempt) {
            // Field presence is the compatibility gate for pre-diagnostics CrawlerX releases.
            if (! isset($attempt['method'], $attempt['started_at'], $attempt['duration_ms'], $attempt['outcome'])) {
                continue;
            }
            if (! is_string($attempt['method']) || ! is_string($attempt['started_at'])
                || ! is_int($attempt['duration_ms']) || ! in_array($attempt['outcome'], ['ok', 'error', 'skipped'], true)) {
                continue;
            }
            $status = $attempt['http_status'] ?? null;
            $rows[] = [
                'created_at' => now(),
                'source_slug' => $queue->source_slug,
                'crawl_queue_id' => $queue->id,
                'url_hash' => hash('sha256', $queue->url),
                'method' => mb_substr((string) $attempt['method'], 0, 64),
                'started_at' => CarbonImmutable::parse($attempt['started_at'])->utc(),
                'duration_ms' => min(2147483647, max(0, $attempt['duration_ms'])),
                'http_status' => is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
                'cf_mitigated' => is_string($attempt['cf_mitigated'] ?? null) ? mb_substr((string) $attempt['cf_mitigated'], 0, 255) : null,
                'outcome' => $attempt['outcome'],
                'error_code' => is_string($attempt['error_code'] ?? null) ? mb_substr((string) $attempt['error_code'], 0, 64) : null,
                'error' => $this->sanitize(is_string($attempt['error'] ?? null) ? $attempt['error'] : null),
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
