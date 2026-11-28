<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Repositories\Api\V2\CrawlLogRepository;
use Carbon\CarbonImmutable;
use Generator;
use stdClass;

/** The crawler event feed: a page of recent events, and the live stream of new ones. */
final class EventFeed
{
    public const POLL_SECONDS = 5;

    public function __construct(private readonly CrawlLogRepository $log) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function page(array $query): array
    {
        $page = PageParams::from($query, 60, 200);
        $kinds = array_values(array_filter(array_map('trim', explode(',', (string) PageParams::text($query['kind'] ?? null))), static fn(string $kind): bool => $kind !== ''));
        $rows = $this->log->events($kinds, PageParams::text($query['source'] ?? null), $page->perPage, $page->offset());

        return [
            'data' => array_map(self::view(...), $rows),
            'meta' => ['kinds' => array_map(static fn(stdClass $row): array => ['kind' => $row->kind, 'n' => (int) $row->n], $this->log->eventKindsSince(CarbonImmutable::now()->subDays(7)))],
        ];
    }

    /**
     * Server-Sent Events frames: every new event, then a keep-alive comment, once per poll. Starts with the events of the last
     * minute (or those after the id the client already has).
     *
     * @return Generator<int, string>
     */
    public function frames(?int $afterId, int $polls, int $intervalSeconds = self::POLL_SECONDS): Generator
    {
        $since = CarbonImmutable::now()->subMinute();
        $last = $afterId;

        for ($poll = 0; $poll < $polls; ++$poll) {
            foreach ($this->log->eventsAfter($last, $since, 100) as $row) {
                $last = (int) $row->id;
                $event = self::view($row);

                yield "id: {$event['id']}\nevent: crawl_event\ndata: " . json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
            }

            yield ": keep-alive\n\n";

            if ($poll < $polls - 1) {
                sleep($intervalSeconds);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function view(stdClass $row): array
    {
        $detail = is_string($row->detail) ? json_decode($row->detail, true) : $row->detail;

        return [
            'id' => (int) $row->id,
            'source_slug' => $row->source_slug,
            'kind' => $row->kind,
            'url' => $row->url,
            'detail' => is_array($detail) ? $detail : null,
            'created_at' => Iso::stamp($row->created_at),
        ];
    }
}
