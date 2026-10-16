<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Crawl;

use App\Enums\CrawlErrorCode;
use App\Jobs\FetchDetailJob;
use App\Models\CrawlQueue;
use App\Models\Source;
use App\Services\Crawl\CrawlQueueService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class CrawlQueueServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_source_url_is_not_enqueued_twice(): void
    {
        $source = Source::factory()->create();
        $url = fake()->url();
        $service = new CrawlQueueService();

        $first = $service->enqueue($url, $source->slug, CrawlQueue::KIND_LISTING);
        $second = $service->enqueue($url, $source->slug, CrawlQueue::KIND_LISTING);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CrawlQueue::query()->where('source_slug', $source->slug)->where('url', $url)->count());
    }

    public function test_stale_claimed_rows_are_reclaimed_to_pending_when_attempts_remain(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
            'locked_by' => fake()->uuid(),
        ]);

        $reclaimed = (new CrawlQueueService())->reclaimStaleClaimed(900);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(CrawlQueue::STATUS_PENDING, $row->refresh()->status);
        $this->assertSame(CrawlErrorCode::WorkerLost->value, $row->error_code);
        $this->assertSame('Worker claim expired before the crawl row was completed.', $row->last_error);
        $this->assertNotNull($row->last_failed_at);
        $this->assertNull($row->claimed_at);
        $this->assertNull($row->locked_by);
    }

    public function test_stale_claimed_rows_exhausted_by_max_attempts_are_failed(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
            'claimed_at' => now()->subHour(),
        ]);

        $reclaimed = (new CrawlQueueService())->reclaimStaleClaimed(900);

        $this->assertSame(1, $reclaimed);
        $this->assertSame(CrawlQueue::STATUS_FAILED, $row->refresh()->status);
        $this->assertSame(CrawlErrorCode::WorkerLost->value, $row->error_code);
        $this->assertNotNull($row->last_error);
        $this->assertNotNull($row->last_failed_at);
    }

    public function test_failed_handler_records_timeout_and_keeps_remaining_attempts(): void
    {
        Carbon::setTestNow('2026-10-16 10:00:00');

        try {
            $row = CrawlQueue::factory()->create([
                'status' => CrawlQueue::STATUS_CLAIMED,
                'attempts' => 1,
                'max_attempts' => 3,
            ]);

            (new FetchDetailJob($row->id))->failed(new TimeoutExceededException('Worker timed out.'));

            $row->refresh();
            $this->assertSame(CrawlQueue::STATUS_PENDING, $row->status);
            $this->assertSame(CrawlErrorCode::Timeout->value, $row->error_code);
            $this->assertSame('Worker timed out while processing the crawl row.', $row->last_error);
            $this->assertNotNull($row->last_failed_at);
            $this->assertSame('2026-10-16 10:01:00', $row->next_attempt_at?->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_failed_handler_records_worker_exception_after_attempts_are_exhausted(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 3,
            'max_attempts' => 3,
        ]);

        (new FetchDetailJob($row->id))->failed(new RuntimeException('Request failed at https://user:pass@example.test/path?token=secret'));

        $row->refresh();
        $this->assertSame(CrawlQueue::STATUS_FAILED, $row->status);
        $this->assertSame(CrawlErrorCode::WorkerFailed->value, $row->error_code);
        $this->assertStringNotContainsString('pass', $row->last_error);
        $this->assertStringNotContainsString('secret', $row->last_error);
        $this->assertNotNull($row->last_failed_at);
    }

    public function test_mark_failed_redacts_sensitive_content_and_limits_message_length(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        (new CrawlQueueService())->markFailed(
            $row,
            CrawlErrorCode::Network,
            '<html><body>Bearer abc123 Basic dGVzdA== access_token=abc123 https://user:pass@example.test/path?token=secret ' . str_repeat('x', 600) . '</body></html>',
            false,
        );

        $message = $row->refresh()->last_error;
        $this->assertSame(CrawlQueue::STATUS_FAILED, $row->status);
        $this->assertLessThanOrEqual(500, mb_strlen($message));
        $this->assertStringNotContainsString('<html>', $message);
        $this->assertStringNotContainsString('abc123', $message);
        $this->assertStringNotContainsString('dGVzdA==', $message);
        $this->assertStringNotContainsString('pass', $message);
        $this->assertStringNotContainsString('?token=', $message);
        $this->assertStringNotContainsString('secret', $message);
    }

    public function test_mark_failed_uses_a_fallback_for_an_empty_message(): void
    {
        $row = CrawlQueue::factory()->create([
            'status' => CrawlQueue::STATUS_CLAIMED,
            'attempts' => 1,
            'max_attempts' => 1,
        ]);

        (new CrawlQueueService())->markFailed($row, CrawlErrorCode::Unknown, '', false);

        $this->assertSame('Crawl failed without an error message.', $row->refresh()->last_error);
    }

    public function test_failed_status_writes_are_centralized_in_the_crawl_queue_service(): void
    {
        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getPathname() === app_path('Services/Crawl/CrawlQueueService.php')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression(
                "/['\"]status['\"]\\s*=>\\s*CrawlQueue::STATUS_FAILED/",
                File::get($file->getPathname()),
                "Direct failed status write found in {$file->getRelativePathname()}.",
            );
        }
    }
}
