<?php

declare(strict_types=1);

namespace Tests\Feature\Repositories;

use App\Models\CrawlQueue;
use App\Repositories\CrawlQueueRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BusinessQueryRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_helpers_keep_reads_and_transactions_behind_the_repository(): void
    {
        $repository = app(CrawlQueueRepository::class);

        self::assertSame(0, $repository->modelQuery(CrawlQueue::class)->count());
        self::assertSame(0, $repository->tableQuery('crawl_queue')->count());
        self::assertSame(0, $repository->databaseQuery()->from('crawl_queue')->count());
        self::assertSame(1, (int) $repository->select('SELECT 1 AS value')[0]->value);
        self::assertSame(1, (int) $repository->selectOne('SELECT 1 AS value')->value);
        self::assertSame('sqlite', $repository->driverName());
        self::assertSame('ok', $repository->transaction(static fn(): string => 'ok'));

        $repository->beginTransaction();
        $rolledBack = CrawlQueue::factory()->create();
        $repository->rollBack();
        self::assertDatabaseMissing('crawl_queue', ['id' => $rolledBack->id]);

        $repository->beginTransaction();
        $committed = CrawlQueue::factory()->create();
        $repository->commit();
        self::assertDatabaseHas('crawl_queue', ['id' => $committed->id]);
    }
}
