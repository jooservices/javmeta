<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Models\CrawlQueue;
use App\Services\Crawl\FetchAttemptRecorder;
use App\Services\Crawler\CrawlerxFetchResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

final class FetchAttemptRecorderTest extends TestCase
{
    public function test_database_failure_warns_without_throwing_or_logging_error_details(): void
    {
        $queue = new CrawlQueue(['source_slug' => 'onejav', 'url' => 'https://example.test?token=private']);
        $queue->id = 12;
        $result = CrawlerxFetchResult::failure('network', 'failure', attempts: [
            ['method' => 'http', 'started_at' => now()->toIso8601String(), 'duration_ms' => 1, 'outcome' => 'error'],
        ]);
        DB::shouldReceive('table')->once()->with('crawl_fetch_attempts')->andThrow(new RuntimeException('private connection details'));
        Log::shouldReceive('warning')->once()->with('Failed to record fetch attempts.', ['exception' => RuntimeException::class, 'queue_id' => 12]);

        (new FetchAttemptRecorder())->record($queue, $result);
        self::assertFalse($result->ok);
    }
}
