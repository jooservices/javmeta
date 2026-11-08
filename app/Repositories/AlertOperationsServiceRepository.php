<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\AlertEvent;
use App\Models\CrawlQueue;
use App\Models\Source;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Database operations used by alert evaluation, metrics, and watchdog checks. */
final class AlertOperationsServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(AlertEvent $model)
    {
        parent::__construct($model);
    }

    /** @return array<string, array<string, mixed>> */
    public function activeEvents(): array
    {
        $events = AlertEvent::query()->whereNull('resolved_at')->get();
        $result = [];
        foreach ($events as $event) {
            $data = $event->toArray();
            $result[(string) $data['rule']] = $data;
        }

        return $result;
    }

    /** @param array<string, mixed> $attributes
     *  @return array<string, mixed>
     */
    public function createEvent(array $attributes): array
    {
        return AlertEvent::query()->create($attributes)->toArray();
    }

    /** @param array<string, mixed> $attributes */
    public function updateEvent(int $id, array $attributes): void
    {
        $event = AlertEvent::query()->findOrFail($id);
        $event->fill($attributes)->save();
    }

    /** @return list<string> */
    public function activeRules(): array
    {
        return AlertEvent::query()->whereNull('resolved_at')->pluck('rule')->map('strval')->all();
    }

    /** @return list<array<string, mixed>> */
    public function pendingRecoveries(): array
    {
        return AlertEvent::query()
            ->whereNotNull('resolved_at')
            ->whereNull('recovery_completed_at')
            ->orderBy('resolved_at')
            ->get()
            ->map(static fn(AlertEvent $event): array => $event->toArray())
            ->all();
    }

    public function enabledSourceCount(): int
    {
        return Source::query()->where('enabled', true)->count();
    }

    public function pendingRecoveryCount(): int
    {
        return AlertEvent::query()->whereNotNull('resolved_at')->whereNull('recovery_completed_at')->count();
    }

    public function pruneResolvedBefore(DateTimeInterface $cutoff): void
    {
        AlertEvent::query()->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff)->delete();
    }

    /** @return list<array<string, mixed>> */
    public function recentEvents(DateTimeInterface $since): array
    {
        return AlertEvent::query()
            ->where(static function (Builder $query) use ($since): void {
                $query->where('started_at', '>=', $since)
                    ->orWhere('resolved_at', '>=', $since)
                    ->orWhereNull('resolved_at');
            })
            ->orderByRaw('COALESCE(resolved_at, started_at) DESC')
            ->get(['rule', 'severity', 'value', 'threshold', 'started_at', 'resolved_at', 'notified_at'])
            ->map(static fn(AlertEvent $event): array => $event->getAttributes())
            ->all();
    }

    /** @return list<string> */
    public function enabledSourceSlugs(): array
    {
        return Source::query()->where('enabled', true)->orderBy('slug')->pluck('slug')->map('strval')->all();
    }

    /** @return array<string, int> */
    public function recentEventCounts(DateTimeInterface $cutoff): array
    {
        $rows = AlertEvent::query()
            ->where(static function (Builder $query) use ($cutoff): void {
                $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', $cutoff);
            })
            ->selectRaw("CASE WHEN resolved_at IS NULL THEN 'firing' ELSE 'resolved' END AS state, severity, COUNT(*) AS event_count")
            ->groupBy('state', 'severity')
            ->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('state') . ':' . (string) $row->getAttribute('severity')] = (int) $row->getAttribute('event_count');
        }

        return $counts;
    }

    public function hasDueQueueWork(): bool
    {
        return CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(static function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->exists();
    }

    public function hasSourceProgressSince(DateTimeInterface $cutoff): bool
    {
        return Source::query()->where('enabled', true)->where('last_success_at', '>=', $cutoff)->exists();
    }

    public function dueQueueSourceList(): string
    {
        $slugs = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(static function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->distinct()->orderBy('source_slug')->limit(20)->pluck('source_slug')->all();

        return $this->formatSlugs($slugs);
    }

    /** @return array{count: int, slugs: string} */
    public function dueQueueBacklog(): array
    {
        $query = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_PENDING)
            ->where(static function (Builder $builder): void {
                $builder->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            });

        return [
            'count' => (clone $query)->count(),
            'slugs' => $this->formatSlugs((clone $query)->distinct()->orderBy('source_slug')->limit(20)->pluck('source_slug')->all()),
        ];
    }

    /** @return array{count: int, slugs: string} */
    public function stuckQueue(DateTimeInterface $cutoff): array
    {
        $query = CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->where('claimed_at', '<', $cutoff);

        return [
            'count' => (clone $query)->count(),
            'slugs' => $this->formatSlugs((clone $query)->distinct()->orderBy('source_slug')->limit(20)->pluck('source_slug')->all()),
        ];
    }

    /** @param list<mixed> $slugs */
    private function formatSlugs(array $slugs): string
    {
        return $slugs === [] ? 'unknown' : implode(', ', array_map('strval', $slugs));
    }
}
