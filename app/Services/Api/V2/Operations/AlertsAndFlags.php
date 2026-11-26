<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\AlertFlagRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use stdClass;

/** The alert history and the review queue of titles that keep failing to crawl. */
final class AlertsAndFlags
{
    public function __construct(
        private readonly AlertFlagRepository $repository,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function alerts(array $query): array
    {
        $page = PageParams::from($query, 40, 100);
        $active = ($query['state'] ?? null) === 'active';

        return [
            'data' => array_map(AlertView::full(...), $this->repository->latestAlerts($page->perPage, $page->offset(), $active)),
            'meta' => Envelope::pagination($page->page, $page->perPage, $this->repository->alertCount($active)),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function flags(array $query): array
    {
        $page = PageParams::from($query, 40, 100);
        $resolved = ($query['state'] ?? 'open') === 'resolved';

        return [
            'data' => array_map(static fn(stdClass $row): array => [
                'id' => (int) $row->id,
                'code_normalized' => $row->code_normalized,
                'source_slug' => $row->source_slug,
                'consecutive_failures' => (int) $row->consecutive_failures,
                'reason' => $row->reason,
                'flagged_at' => Iso::stamp($row->flagged_at),
                'resolved_at' => Iso::stamp($row->resolved_at),
            ], $this->repository->flags($resolved, $page->perPage, $page->offset())),
            'meta' => Envelope::pagination($page->page, $page->perPage, $this->repository->flagCount($resolved)),
        ];
    }

    /** @return array{id: int, state: string} */
    public function resolve(int $id, ?string $reason, ?int $actorId): array
    {
        if (! $this->repository->resolveFlag($id)) {
            throw ProblemException::notFound('flag_not_found', 'No such flag.');
        }

        $this->audit->record($actorId, "review-flags/{$id}", 'patch', ['state' => 'resolved', 'reason' => $reason]);

        return ['id' => $id, 'state' => 'resolved'];
    }
}
