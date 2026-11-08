<?php

declare(strict_types=1);

namespace App\Data\Operations;

/** The current operational dashboard data returned as one stable snapshot. */
final readonly class DashboardSnapshot
{
    /**
     * @param array{movies: int, performers: int, movies_first_seen_1h: int, failed_jobs: int} $totals
     * @param array<string, int> $queue
     * @param array{runs: int, pages: int, movies_new: int, movies_updated: int, failures: int} $hour
     * @param list<array<string, mixed>> $sources
     * @param list<array<string, mixed>> $issues
     * @param list<array<string, mixed>> $events
     */
    public function __construct(
        public string $asOf,
        public bool $uiLoginRequired,
        public string $uiLoginState,
        public array $totals,
        public array $queue,
        public array $hour,
        public array $sources,
        public array $issues,
        public array $events,
    ) {}

    /** @return array{as_of: string, ui_login_required: bool, ui_login_state: string, totals: array{movies: int, performers: int, movies_first_seen_1h: int, failed_jobs: int}, queue: array<string, int>, hour: array{runs: int, pages: int, movies_new: int, movies_updated: int, failures: int}, sources: list<array<string, mixed>>, issues: list<array<string, mixed>>, events: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'as_of' => $this->asOf,
            'ui_login_required' => $this->uiLoginRequired,
            'ui_login_state' => $this->uiLoginState,
            'totals' => $this->totals,
            'queue' => $this->queue,
            'hour' => $this->hour,
            'sources' => $this->sources,
            'issues' => $this->issues,
            'events' => $this->events,
        ];
    }
}
