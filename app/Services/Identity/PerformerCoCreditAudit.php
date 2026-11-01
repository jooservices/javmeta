<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use Illuminate\Support\Facades\DB;

final class PerformerCoCreditAudit
{
    public function __construct(private readonly PerformerCoCreditRule $rule) {}

    /** @param array<string, mixed> $evidence */
    public function record(PerformerIdentityEvent $event, array $evidence): int
    {
        $left = PerformerSource::query()->where('source_performer_id', $evidence['source_id'])->firstOrFail();
        $right = PerformerSource::query()->where('source_performer_id', $evidence['candidate_source_id'])->firstOrFail();
        foreach ($this->rule->movies($left, $right, $evidence['normalized_name'])->orderBy('m.id')->lazy(200) as $movie) {
            DB::table('performer_identity_event_movie_links')->insert([
                'event_id' => $event->id, 'movie_id' => $movie->id,
                'source_slug' => $movie->source_slug, 'crawled_at' => $movie->crawled_at,
                'evidence_source_performer_id' => $evidence['source_id'],
                'evidence_candidate_performer_id' => $evidence['candidate_source_id'],
            ]);
        }
        $ids = [(int) $evidence['source_id'], (int) $evidence['candidate_source_id']];
        $reviews = DB::table('performer_identity_reviews')->where('status', 'open')
            ->whereIn('source_performer_id', $ids)->whereIn('candidate_performer_id', $ids)->get();
        $details = (array) $event->getAttribute('details');
        $details['closed_reviews'] = $reviews->map(static fn(object $row): array => (array) $row)->all();
        $event->setAttribute('details', $details);
        $event->save();
        foreach ($reviews as $review) {
            DB::table('performer_identity_reviews')->where('id', $review->id)->update([
                'status' => 'resolved_co_credit', 'reason' => 'co_credit', 'resolved_at' => now(),
            ]);
        }
        $this->refreshFlags($ids);

        return $reviews->count();
    }

    public function restore(PerformerIdentityEvent $event): void
    {
        $details = (array) $event->getAttribute('details');
        foreach ($details['closed_reviews'] ?? [] as $review) {
            DB::table('performer_identity_reviews')->where('id', $review['id'])->update($review);
        }
        $evidence = $details['co_credit'] ?? null;
        if (is_array($evidence)) {
            $this->refreshFlags([(int) $evidence['source_id'], (int) $evidence['candidate_source_id']]);
        }
    }

    public function reclose(PerformerIdentityEvent $event): void
    {
        $details = (array) $event->getAttribute('details');
        foreach ($details['closed_reviews'] ?? [] as $review) {
            DB::table('performer_identity_reviews')->where('id', $review['id'])->update([
                'status' => 'resolved_co_credit', 'reason' => 'co_credit', 'resolved_at' => now(),
            ]);
        }
        $evidence = $details['co_credit'] ?? null;
        if (is_array($evidence)) {
            $this->refreshFlags([(int) $evidence['source_id'], (int) $evidence['candidate_source_id']]);
        }
    }

    /** @param list<int> $ids */
    private function refreshFlags(array $ids): void
    {
        foreach ($ids as $id) {
            $open = DB::table('performer_identity_reviews')->where('status', 'open')
                ->where(static fn($query) => $query->where('source_performer_id', $id)->orWhere('candidate_performer_id', $id))->exists();
            Performer::query()->whereKey($id)->update(['needs_review' => $open]);
        }
    }
}
