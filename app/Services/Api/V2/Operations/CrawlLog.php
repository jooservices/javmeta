<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\CrawlLogRepository;
use Carbon\CarbonImmutable;
use stdClass;

/** Crawl runs, their fetch attempts, the queue by source and the failures of the last day. */
final class CrawlLog
{
    public function __construct(private readonly CrawlLogRepository $log) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function runs(array $query): array
    {
        $page = PageParams::from($query, 40, 100);
        $source = PageParams::text($query['source'] ?? null);
        $status = PageParams::text($query['status'] ?? null);

        return [
            'data' => array_map(self::run(...), $this->log->runs($source, $status, $page->perPage, $page->offset())),
            'meta' => Envelope::pagination($page->page, $page->perPage, $this->log->runCount($source, $status)),
        ];
    }

    /** @return array<string, mixed> */
    public function find(int $id): array
    {
        $row = $this->existing($id);

        return self::run($row) + ['proxy_requests' => (int) $row->proxy_requests, 'proxy_bytes' => (int) $row->proxy_bytes];
    }

    /** @return list<array<string, mixed>> */
    public function attempts(int $id): array
    {
        return array_map(static fn(stdClass $row): array => [
            'created_at' => Iso::stamp($row->created_at),
            'method' => $row->method,
            'duration_ms' => (int) $row->duration_ms,
            'http_status' => $row->http_status === null ? null : (int) $row->http_status,
            'outcome' => $row->outcome,
            'error_code' => $row->error_code,
            'lane' => $row->lane,
            'node' => $row->node,
        ], $this->log->attemptsOfRun($this->existing($id)));
    }

    /** @return list<array<string, mixed>> */
    public function queue(): array
    {
        return array_map(static fn(stdClass $row): array => [
            'source_slug' => $row->source_slug,
            'status' => $row->status,
            'n' => (int) $row->n,
            'oldest_next' => Iso::stamp($row->oldest_next),
            'oldest_claim' => Iso::stamp($row->oldest_claim),
            'stale' => (int) $row->stale,
        ], $this->log->queue(CarbonImmutable::now()->subMinutes(10)));
    }

    /** @return list<array<string, mixed>> */
    public function errors(): array
    {
        return array_map(static fn(stdClass $row): array => [
            'source_slug' => $row->source_slug,
            'error_code' => $row->error_code,
            'n' => (int) $row->n,
            'last_at' => Iso::stamp($row->last_at),
        ], $this->log->errorsSince(CarbonImmutable::now()->subHours(24)));
    }

    private function existing(int $id): stdClass
    {
        return $this->log->run($id) ?? throw ProblemException::notFound('run_not_found', 'No such run.');
    }

    /** @return array<string, mixed> */
    private static function run(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'source_slug' => $row->source_slug,
            'started_at' => Iso::stamp($row->started_at),
            'finished_at' => Iso::stamp($row->finished_at),
            'status' => $row->status,
            'pages_fetched' => (int) $row->pages_fetched,
            'movies_new' => (int) $row->movies_new,
            'movies_updated' => (int) $row->movies_updated,
            'failures' => (int) $row->failures,
            'browser_fetches' => (int) $row->browser_fetches,
        ];
    }
}
