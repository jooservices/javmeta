<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PerformerCoCreditAudit
{
    public function __construct(private readonly PerformerCoCreditRule $rule) {}

    /** @param array<string, mixed> $evidence */
    public function record(PerformerIdentityEvent $event, array $evidence): int
    {
        $this->recordMovies($event, $evidence);
        $details = (array) $event->getAttribute('details');
        $moved = $details['source_performer_ids'];
        $target = $this->groupIds((int) $event->to_performer_id)->whereNotIn('id', $moved);
        $closed = 0;
        // Both directions start with the indexed source column; snapshots never accumulate in event JSON.
        $reviews = DB::table('performer_identity_reviews')->where('status', 'open')
            ->where(static function ($query) use ($moved, $target): void {
                $query->where(static fn($side) => $side->whereIn('source_performer_id', $moved)->whereIn('candidate_performer_id', $target))
                    ->orWhere(static fn($side) => $side->whereIn('source_performer_id', $target)->whereIn('candidate_performer_id', $moved));
            });
        $reviews->chunkById(200, function ($page) use ($event, &$closed): void {
            $snapshots = [];
            foreach ($page as $review) {
                $snapshots[] = ['event_id' => $event->id, 'review_id' => $review->id,
                    'snapshot' => json_encode((array) $review, JSON_THROW_ON_ERROR)];
            }
            DB::table('performer_identity_event_reviews')->insert($snapshots);
            DB::table('performer_identity_reviews')->whereIn('id', $page->pluck('id')->all())->update([
                'status' => 'resolved_co_credit', 'reason' => 'co_credit', 'resolved_at' => now(),
            ]);
            $closed += $page->count();
        });
        $this->refreshFlags($event);

        return $closed;
    }

    /** @param array<string, mixed> $evidence */
    private function recordMovies(PerformerIdentityEvent $event, array $evidence): void
    {
        $left = PerformerSource::query()->where('source_performer_id', $evidence['source_id'])->firstOrFail();
        $right = PerformerSource::query()->where('source_performer_id', $evidence['candidate_source_id'])->firstOrFail();
        $this->rule->movies($left, $right, $evidence['normalized_name'])->orderBy('m.id')
            ->chunk(200, static function ($page) use ($event, $evidence): void {
                $rows = [];
                foreach ($page as $movie) {
                    $rows[] = [
                        'event_id' => $event->id, 'movie_id' => $movie->id,
                        'source_slug' => $movie->source_slug, 'crawled_at' => $movie->crawled_at,
                        'evidence_source_performer_id' => $evidence['source_id'],
                        'evidence_candidate_performer_id' => $evidence['candidate_source_id'],
                    ];
                }
                DB::table('performer_identity_event_movie_links')->insert($rows);
            });
    }

    public function restore(PerformerIdentityEvent $event): void
    {
        foreach (DB::table('performer_identity_event_reviews')->where('event_id', $event->id)->lazyById(200) as $record) {
            $snapshot = json_decode((string) $record->snapshot, true, 512, JSON_THROW_ON_ERROR);
            DB::table('performer_identity_reviews')->where('id', $record->review_id)->update($snapshot);
        }
        $this->refreshFlags($event);
    }

    public function reclose(PerformerIdentityEvent $event): void
    {
        DB::table('performer_identity_event_reviews')->where('event_id', $event->id)
            ->chunkById(200, static function ($page): void {
                DB::table('performer_identity_reviews')->whereIn('id', $page->pluck('review_id')->all())->update([
                    'status' => 'resolved_co_credit', 'reason' => 'co_credit', 'resolved_at' => now(),
                ]);
            });
        $this->refreshFlags($event);
    }

    /** Membership uses the primary key and canonical index as separate branches. */
    private function groupIds(int $rootId): Builder
    {
        $members = DB::table('performers')->where('id', $rootId)->select('id')
            ->union(DB::table('performers')->where('canonical_performer_id', $rootId)->select('id'));

        return DB::query()->fromSub($members, 'members')->select('id');
    }

    private function refreshFlags(PerformerIdentityEvent $event): void
    {
        $details = (array) $event->getAttribute('details');
        if (! is_array($details['co_credit'] ?? null)) {
            return;
        }
        $evidence = $details['co_credit'];
        $ids = array_unique([(int) $evidence['source_id'], (int) $evidence['candidate_source_id'],
            (int) $event->from_performer_id, (int) $event->to_performer_id]);
        foreach ($ids as $id) {
            $rootId = (int) (Performer::query()->whereKey($id)->value('canonical_performer_id') ?? $id);
            $members = $this->groupIds($rootId);
            $open = DB::table('performer_identity_reviews')->where('status', 'open')
                ->where(static fn($query) => $query->whereIn('source_performer_id', $members)->orWhereIn('candidate_performer_id', $members))->exists();
            Performer::query()->whereKey($id)->update(['needs_review' => $open]);
        }
    }
}
