<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CrawlQueue;
use App\Services\Crawl\CrawlStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class StatusController extends BaseApiController
{
    /** Totals, ingest, queue, workers and the 24 hour runs: the same data as the MCP tool crawl_status. Cached for 15 seconds. */
    public function summary(CrawlStatusService $status): JsonResponse
    {
        $data = Cache::remember('javmeta:status:summary', 15, static function () use ($status): array {
            $data = $status->status();
            unset($data['sources']);

            return $data;
        });

        return $this->respondWithData(data: $data, message: 'Crawl status retrieved successfully.');
    }

    /** One row per source: switch, gap, last success and error, and its queue by status. */
    public function sources(CrawlStatusService $status): JsonResponse
    {
        return $this->respondWithData(data: Cache::remember('javmeta:status:sources', 15, fn(): array => $this->sourceRows($status)), message: 'Source status retrieved successfully.');
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(CrawlStatusService $status): array
    {
        $queue = [];
        foreach (DB::table('crawl_queue')->select('source_slug', 'status', DB::raw('COUNT(*) AS total'))->groupBy('source_slug', 'status')->get() as $row) {
            $queue[(string) $row->source_slug][(string) $row->status] = (int) $row->total;
        }

        $rows = [];
        foreach ($status->status()['sources'] as $source) {
            $counts = $queue[$source['slug']] ?? [];
            $rows[] = [
                'slug' => $source['slug'],
                'name' => $source['name'],
                'enabled' => $source['enabled'],
                'gap_seconds_current' => $source['gap_seconds_current'],
                'gap_seconds_min' => $source['gap_seconds_min'],
                'gap_seconds_max' => $source['gap_seconds_max'],
                'last_success_at' => $source['last_success_at'],
                'last_error_at' => $source['last_error_at'],
                'last_error' => $source['last_error'] === null ? null : mb_substr((string) $source['last_error'], 0, 200),
                'queue' => [
                    CrawlQueue::STATUS_PENDING => $counts[CrawlQueue::STATUS_PENDING] ?? 0,
                    CrawlQueue::STATUS_CLAIMED => $counts[CrawlQueue::STATUS_CLAIMED] ?? 0,
                    CrawlQueue::STATUS_DONE => $counts[CrawlQueue::STATUS_DONE] ?? 0,
                    CrawlQueue::STATUS_FAILED => $counts[CrawlQueue::STATUS_FAILED] ?? 0,
                ],
            ];
        }

        return $rows;
    }
}
