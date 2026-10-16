<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\CrawlQueue;
use App\Models\Source;
use Illuminate\Support\Facades\DB;

final class CrawlQueueService
{
    public function claimNext(string $workerId): ?CrawlQueue
    {
        return DB::transaction(function () use ($workerId): ?CrawlQueue {
            $query = CrawlQueue::query()
                ->where('status', CrawlQueue::STATUS_PENDING)
                ->where(function ($q): void {
                    $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
                })
                ->orderBy('id');

            if (DB::getDriverName() === 'pgsql') {
                $query->lockForUpdate();
            }

            $row = $query->first();
            if (! $row instanceof CrawlQueue) {
                return null;
            }

            $row->forceFill([
                'status' => CrawlQueue::STATUS_CLAIMED,
                'claimed_at' => now(),
                'locked_by' => $workerId,
                'attempts' => $row->attempts + 1,
            ])->save();

            return $row->refresh();
        });
    }

    public function enqueue(string $url, string $sourceSlug, string $kind, ?int $maxAttempts = null): CrawlQueue
    {
        $source = Source::query()->find($sourceSlug);
        $configuredMaxAttempts = $maxAttempts ?? (int) config("javmeta_sources.sources.{$sourceSlug}.max_attempts", config('javmeta_sources.defaults.max_attempts', 3));

        return CrawlQueue::query()->firstOrCreate(
            ['source_slug' => $sourceSlug, 'url' => $url],
            [
                'kind' => $kind,
                'status' => CrawlQueue::STATUS_PENDING,
                'attempts' => 0,
                'max_attempts' => $configuredMaxAttempts,
                'next_attempt_at' => now()->addSeconds((int) ($source->gap_seconds_current ?? 0)),
                'claimed_at' => null,
                'locked_by' => null,
                'last_error' => null,
            ],
        );
    }

    public function reclaimStaleClaimed(int $timeoutSeconds): int
    {
        $cutoff = now()->subSeconds($timeoutSeconds);

        $exhausted = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->where('claimed_at', '<', $cutoff)
            ->whereColumn('attempts', '>=', 'max_attempts')
            ->update([
                'status' => CrawlQueue::STATUS_FAILED,
                'claimed_at' => null,
                'locked_by' => null,
            ]);

        $pending = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_CLAIMED)
            ->where('claimed_at', '<', $cutoff)
            ->whereColumn('attempts', '<', 'max_attempts')
            ->update([
                'status' => CrawlQueue::STATUS_PENDING,
                'claimed_at' => null,
                'locked_by' => null,
            ]);

        return $exhausted + $pending;
    }
}
