<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Validates recorded merge events before a split or an undo and parses their stored details.
 */
final class PerformerMergeGuard
{
    /** @param array<string, mixed> $details */
    public function assertMergeCanBeSplit(PerformerIdentityEvent $merge, array $details): void
    {
        $canonicalId = (int) $merge->to_performer_id;
        $canonical = Performer::query()->find($canonicalId);
        if (! $canonical instanceof Performer || $canonical->canonical_performer_id !== null) {
            throw new RuntimeException('Split later merge events first; the recorded canonical performer has changed.');
        }

        $members = Performer::query()->whereIn('id', $details['source_performer_ids'])
            ->get(['id', 'canonical_performer_id'])->keyBy('id');
        foreach ($details['source_performer_ids'] as $performerId) {
            $member = $members->get($performerId);
            if (! $member instanceof Performer || (int) $member->canonical_performer_id !== $canonicalId) {
                throw new RuntimeException('Split later merge events first; the recorded performer group has changed.');
            }
        }

        $sourceLinksChanged = DB::table('performer_sources')->whereIn('id', $details['source_link_ids'])
            ->where('performer_id', '<>', $canonicalId)->exists();
        if ($sourceLinksChanged) {
            throw new RuntimeException('Split later merge events first; the recorded source links have changed.');
        }
    }

    /** @param array<string, mixed> $details */
    public function assertSplitCanBeUndone(PerformerIdentityEvent $merge, array $details): void
    {
        if ($merge->reversed_at === null) {
            throw new RuntimeException('The merge event is not currently split.');
        }

        $fromId = (int) $merge->from_performer_id;
        $toId = (int) $merge->to_performer_id;
        $from = Performer::query()->find($fromId);
        $to = Performer::query()->find($toId);
        if (! $from instanceof Performer || $from->canonical_performer_id !== null
            || ! $to instanceof Performer || $to->canonical_performer_id !== null) {
            throw new RuntimeException('Restore later identity changes before undoing this split.');
        }

        if (! in_array($fromId, $details['source_performer_ids'], true)) {
            throw new RuntimeException('The split event does not contain its original source performer.');
        }

        $children = array_values(array_diff($details['source_performer_ids'], [$fromId]));
        if ($children !== []) {
            $linkedChildren = Performer::query()->whereIn('id', $children)
                ->where('canonical_performer_id', $fromId)->count();
            if ($linkedChildren !== count($children)) {
                throw new RuntimeException('Restore later identity changes before undoing this split.');
            }
        }

        $unexpectedChildren = Performer::query()->where('canonical_performer_id', $fromId)
            ->whereNotIn('id', $details['source_performer_ids'])->exists();
        if ($unexpectedChildren) {
            throw new RuntimeException('Restore later identity changes before undoing this split.');
        }

        $sourceLinksChanged = DB::table('performer_sources')->whereIn('id', $details['source_link_ids'])
            ->where('performer_id', '<>', $fromId)->exists();
        if ($sourceLinksChanged) {
            throw new RuntimeException('Restore later identity changes before undoing this split.');
        }
    }

    /**
     * @return array{
     *     source_performer_ids: list<int>,
     *     source_link_ids: list<int>,
     *     alias_owners: list<array{id: int, performer_id: int}>,
     *     media_owners: list<array{id: int, performer_id: int}>,
     *     created_movie_links: list<array{movie_id: int, source_slug: string, crawled_at: string}>
     * }
     */
    public function mergeDetails(PerformerIdentityEvent $event): array
    {
        $details = $event->getAttribute('details');
        if (! is_array($details)) {
            throw new RuntimeException('The merge event details are invalid.');
        }

        $sourceIds = $this->integerList($details['source_performer_ids'] ?? null);
        $sourceLinkIds = $this->integerList($details['source_link_ids'] ?? null);
        $aliasOwners = $this->relationOwnerList($details['alias_owners'] ?? null);
        $mediaOwners = $this->relationOwnerList($details['media_owners'] ?? null);
        $movieLinks = $this->movieLinkList($details['created_movie_links'] ?? null);
        if ($sourceIds === null || $sourceLinkIds === null || $aliasOwners === null || $mediaOwners === null || $movieLinks === null) {
            throw new RuntimeException('The merge event details are incomplete.');
        }

        return [
            'source_performer_ids' => $sourceIds,
            'source_link_ids' => $sourceLinkIds,
            'alias_owners' => $aliasOwners,
            'media_owners' => $mediaOwners,
            'created_movie_links' => $movieLinks,
        ];
    }

    /** @return list<int>|null */
    private function integerList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $items = [];
        foreach ($value as $item) {
            if (! is_int($item) && !(is_string($item) && ctype_digit($item))) {
                return null;
            }
            $items[] = (int) $item;
        }

        return $items;
    }

    /** @return list<array{id: int, performer_id: int}>|null */
    private function relationOwnerList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $owners = [];
        foreach ($value as $owner) {
            if (! is_array($owner) || ! is_numeric($owner['id'] ?? null) || ! is_numeric($owner['performer_id'] ?? null)) {
                return null;
            }
            $owners[] = ['id' => (int) $owner['id'], 'performer_id' => (int) $owner['performer_id']];
        }

        return $owners;
    }

    /** @return list<array{movie_id: int, source_slug: string, crawled_at: string}>|null */
    private function movieLinkList(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $links = [];
        foreach ($value as $link) {
            if (! is_array($link)
                || ! is_numeric($link['movie_id'] ?? null)
                || ! is_string($link['source_slug'] ?? null)
                || ! is_string($link['crawled_at'] ?? null)) {
                return null;
            }
            $links[] = [
                'movie_id' => (int) $link['movie_id'],
                'source_slug' => $link['source_slug'],
                'crawled_at' => $link['crawled_at'],
            ];
        }

        return $links;
    }
}
