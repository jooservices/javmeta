<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Crawl;

use App\Jobs\FetchDetailJob;
use App\Jobs\FetchGalleryJob;
use App\Jobs\FetchListingJob;
use App\Jobs\FetchPerformerDetailJob;
use App\Jobs\FetchPerformerListingJob;
use App\Models\CrawlQueue;
use App\Support\Crawl\LaravelCrawlQueue;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class LaravelCrawlQueueTest extends TestCase
{
    public function test_all_kinds_map_to_named_queues(): void
    {
        $this->assertSame(
            ['listing', 'detail', 'performer_listing', 'performer_detail', 'gallery'],
            LaravelCrawlQueue::allNames(),
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function kindProvider(): array
    {
        return [
            'listing' => [CrawlQueue::KIND_LISTING, 'listing'],
            'detail' => [CrawlQueue::KIND_DETAIL, 'detail'],
            'performer_listing' => [CrawlQueue::KIND_PERFORMER_LISTING, 'performer_listing'],
            'performer_detail' => [CrawlQueue::KIND_PERFORMER_DETAIL, 'performer_detail'],
            'gallery' => [CrawlQueue::KIND_GALLERY, 'gallery'],
        ];
    }

    #[DataProvider('kindProvider')]
    public function test_name_for_kind(string $kind, string $queue): void
    {
        $this->assertSame($queue, LaravelCrawlQueue::nameForKind($kind));
    }

    public function test_jobs_bind_matching_queue_names(): void
    {
        $this->assertSame('listing', (new FetchListingJob(1))->queue);
        $this->assertSame('detail', (new FetchDetailJob(1))->queue);
        $this->assertSame('performer_listing', (new FetchPerformerListingJob(1))->queue);
        $this->assertSame('performer_detail', (new FetchPerformerDetailJob(1))->queue);
        $this->assertSame('gallery', (new FetchGalleryJob(1))->queue);
    }

    public function test_ready_depth_counts_only_available_unreserved_database_jobs_for_the_named_queue(): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => [
                'driver' => 'database',
                'connection' => 'sqlite',
                'table' => 'jobs',
            ],
        ]);
        Schema::dropIfExists('jobs');
        Schema::create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->timestamp('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
        });

        try {
            $now = now()->getTimestamp();
            DB::table('jobs')->insert([
                ['queue' => 'listing', 'reserved_at' => null, 'available_at' => $now - 1],
                ['queue' => 'listing', 'reserved_at' => null, 'available_at' => $now + 60],
                ['queue' => 'listing', 'reserved_at' => $now - 1, 'available_at' => $now - 1],
                ['queue' => 'detail', 'reserved_at' => null, 'available_at' => $now - 1],
            ]);

            $this->assertSame(1, LaravelCrawlQueue::readyDepth('listing'));
        } finally {
            Schema::dropIfExists('jobs');
        }
    }

    public function test_ready_depth_reads_the_redis_ready_list_for_the_selected_connection(): void
    {
        config([
            'queue.default' => 'redis',
            'queue.connections.redis' => [
                'driver' => 'redis',
                'connection' => 'queues',
            ],
        ]);
        $connection = \Mockery::mock();
        $connection->shouldReceive('llen')->once()->with('queues:listing')->andReturn(7);
        Redis::shouldReceive('connection')->once()->with('queues')->andReturn($connection);

        $this->assertSame(7, LaravelCrawlQueue::readyDepth('listing'));
    }

    public function test_ready_depth_is_zero_for_nonbuffered_queue_connections(): void
    {
        config(['queue.default' => 'sync', 'queue.connections.sync.driver' => 'sync']);

        $this->assertSame(0, LaravelCrawlQueue::readyDepth('listing'));
    }
}
