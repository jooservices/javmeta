<?php

declare(strict_types=1);

namespace Tests\Feature\Crawl;

use App\Models\CrawlQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RequeueProductCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_preserves_rows_and_requeue_is_scoped_and_idempotent(): void
    {
        foreach (['javbtc', 'ffjav', 'tokyohot', '141jav'] as $slug) {
            foreach (['parse_failed', 'normalization_failed'] as $error) {
                CrawlQueue::factory()->create([
                    'source_slug' => $slug, 'kind' => CrawlQueue::KIND_DETAIL,
                    'status' => CrawlQueue::STATUS_FAILED, 'error_code' => $error,
                    'attempts' => 5, 'locked_by' => 'old-worker', 'last_error' => 'Missing code',
                    'claimed_at' => now(), 'started_at' => now(), 'last_failed_at' => now(),
                ]);
            }
        }
        $untouched = [
            ['source_slug' => 'missav'], ['error_code' => 'network'],
            ['status' => CrawlQueue::STATUS_DONE], ['status' => CrawlQueue::STATUS_CLAIMED],
            ['kind' => CrawlQueue::KIND_LISTING],
        ];
        foreach ($untouched as $attributes) {
            CrawlQueue::factory()->create(array_merge([
                'source_slug' => 'javbtc', 'kind' => CrawlQueue::KIND_DETAIL,
                'status' => CrawlQueue::STATUS_FAILED, 'error_code' => 'parse_failed',
            ], $attributes));
        }

        $before = CrawlQueue::query()->get()->toArray();
        $this->artisan('crawler:requeue-product-codes', ['--dry-run' => true])
            ->expectsOutput('javbtc: 2 would requeue')->expectsOutput('ffjav: 2 would requeue')
            ->expectsOutput('tokyohot: 2 would requeue')->expectsOutput('141jav: 2 would requeue')->assertSuccessful();
        self::assertSame($before, CrawlQueue::query()->get()->toArray());

        $this->artisan('crawler:requeue-product-codes')->assertSuccessful();
        self::assertSame(8, CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count());
        self::assertSame(5, CrawlQueue::query()->where('status', '!=', CrawlQueue::STATUS_PENDING)->count());
        foreach (CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->get() as $row) {
            self::assertSame(0, $row->attempts);
            foreach (['locked_by', 'claimed_at', 'started_at', 'last_error', 'error_code', 'last_failed_at'] as $field) {
                self::assertNull($row->getAttribute($field));
            }
            self::assertNotNull($row->next_attempt_at);
        }
        $this->artisan('crawler:requeue-product-codes')->expectsOutput('javbtc: 0 requeued')->assertSuccessful();
    }
}
