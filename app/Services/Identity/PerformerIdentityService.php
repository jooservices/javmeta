<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PerformerIdentityService
{
    public function __construct(
        private readonly PerformerIdentityMatcher $matcher,
        private readonly ElasticsearchIndexer $indexer,
    ) {}

    /** @return array{merges: list<array<string, mixed>>, reviews: list<array<string, mixed>>} */
    public function backfill(bool $dryRun = false): array
    {
        $missingSources = $this->ensureSourceLinks($dryRun);
        $merges = [];
        $reviews = [];
        $plannedReviews = [];
        $sources = PerformerSource::query()->whereNull('removed_at')->orderBy('id')->get()
            ->concat($missingSources);
        $plannedPairs = [];

        foreach ($sources as $source) {
            $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
            $currentPerformerId = (int) $source->performer_id;
            if (! $dryRun && $source->exists) {
                $this->refreshNameKeys($source);
            }
            if ($sourcePerformerId !== $currentPerformerId) {
                continue;
            }
            if (Performer::query()->whereKey($currentPerformerId)->value('canonical_performer_id') !== null) {
                continue;
            }

            $evaluation = $this->evaluateSource($source, true);
            if ($evaluation['decision'] === 'merge') {
                $candidateId = (int) $evaluation['candidate_id'];
                $fromId = max($currentPerformerId, $candidateId);
                $toId = min($currentPerformerId, $candidateId);
                $pair = $fromId . ':' . $toId;
                if (isset($plannedPairs[$pair])) {
                    continue;
                }
                $plannedPairs[$pair] = true;
                $merge = [
                    'source_performer_id' => $sourcePerformerId,
                    'from_performer_id' => $fromId,
                    'to_performer_id' => $toId,
                    'reason' => $evaluation['reason'],
                ];
                $merges[] = $merge;

                if (! $dryRun) {
                    $this->mergeRoots($fromId, $toId, $evaluation);
                }
            } elseif ($evaluation['decision'] === 'review') {
                foreach ($evaluation['review_candidates'] as $candidate) {
                    $reviewPair = min($sourcePerformerId, (int) $candidate['id']) . ':'
                        . max($sourcePerformerId, (int) $candidate['id']);
                    if (isset($plannedReviews[$reviewPair])) {
                        continue;
                    }
                    $plannedReviews[$reviewPair] = true;
                    $review = [
                        'source_performer_id' => $sourcePerformerId,
                        'candidate_performer_id' => $candidate['id'],
                        'reason' => $candidate['reason'],
                        'similarity' => $candidate['similarity'],
                    ];
                    $reviews[] = $review;

                    if (! $dryRun) {
                        $this->recordReview($review);
                    }
                }
            }
        }

        return ['merges' => $merges, 'reviews' => $reviews];
    }

    public function reconcileSource(PerformerSource $source): int
    {
        $source->refresh();
        $currentId = (int) $source->performer_id;
        $this->refreshNameKeys($source);
        $evaluation = $this->evaluateSource($source);

        if ($evaluation['decision'] === 'merge') {
            $candidateId = (int) $evaluation['candidate_id'];
            $fromId = max($currentId, $candidateId);
            $toId = min($currentId, $candidateId);
            $this->mergeRoots($fromId, $toId, $evaluation);

            return $toId;
        }

        if ($evaluation['decision'] === 'review') {
            foreach ($evaluation['review_candidates'] as $candidate) {
                $this->recordReview([
                    'source_performer_id' => (int) $source->source_performer_id,
                    'candidate_performer_id' => $candidate['id'],
                    'reason' => $candidate['reason'],
                    'similarity' => $candidate['similarity'],
                ]);
            }
        }

        return $this->canonicalId($currentId);
    }

    /** Reconciles a freshly persisted source record and moves its aliases and media to the canonical performer. */
    public function reconcileAndSync(PerformerSource $source): int
    {
        $canonicalId = $this->reconcileSource($source);
        $this->syncSourceContributions($source->refresh());

        return $canonicalId;
    }

    public function syncSourceContributions(PerformerSource $source): void
    {
        $canonicalId = $this->canonicalId((int) $source->performer_id);
        $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
        DB::table('performer_aliases')->where('source_performer_id', $sourcePerformerId)
            ->update(['performer_id' => $canonicalId]);
        DB::table('performer_media')->where('source_performer_id', $sourcePerformerId)
            ->update(['performer_id' => $canonicalId]);
    }

    public function canonicalId(int $performerId): int
    {
        $performer = Performer::query()->find($performerId);
        if (! $performer instanceof Performer) {
            return $performerId;
        }

        while ($performer->canonical_performer_id !== null) {
            $next = Performer::query()->find((int) $performer->canonical_performer_id);
            if (! $next instanceof Performer || (int) $next->id === (int) $performer->id) {
                break;
            }
            $performer = $next;
        }

        return (int) $performer->id;
    }

    /** A removed source profile is retained as history and does not remove its canonical performer. */
    public function markSourceRemoved(string $siteSlug, string $externalId): bool
    {
        $source = PerformerSource::query()
            ->where('site_slug', $siteSlug)
            ->where('external_id', $externalId)
            ->first();

        if (! $source instanceof PerformerSource) {
            return false;
        }

        $source->removed_at = now()->toDateTimeString();
        $source->save();
        $this->reindexPerformers([(int) $source->performer_id]);

        return true;
    }

    public function split(int $mergeEventId): PerformerIdentityEvent
    {
        $merge = PerformerIdentityEvent::query()
            ->whereKey($mergeEventId)
            ->where('event_type', 'merge')
            ->whereNull('reversed_at')
            ->first();
        if (! $merge instanceof PerformerIdentityEvent) {
            throw new RuntimeException('An active merge event was not found.');
        }

        $details = $this->mergeDetails($merge);
        $this->assertMergeCanBeSplit($merge, $details);
        $split = DB::transaction(function () use ($merge, $details): PerformerIdentityEvent {
            DB::table('performer_sources')->whereIn('id', $details['source_link_ids'])
                ->update(['performer_id' => $merge->from_performer_id]);

            $sourceIds = $details['source_performer_ids'];
            Performer::query()->whereIn('id', $sourceIds)->update(['canonical_performer_id' => $merge->from_performer_id]);
            Performer::query()->whereKey($merge->from_performer_id)->update(['canonical_performer_id' => null]);

            $this->restoreRelationOwners('performer_aliases', $details['alias_owners']);
            $this->restoreRelationOwners('performer_media', $details['media_owners']);
            // Rows added after the merge belong to the split-off root, which the API reads as canonical.
            DB::table('performer_aliases')->where('performer_id', $merge->to_performer_id)
                ->whereIn('source_performer_id', $sourceIds)->update(['performer_id' => $merge->from_performer_id]);
            DB::table('performer_media')->where('performer_id', $merge->to_performer_id)
                ->whereIn('source_performer_id', $sourceIds)->update(['performer_id' => $merge->from_performer_id]);
            $this->copyMovieLinks($sourceIds, (int) $merge->from_performer_id);
            $this->removeCreatedMovieLinks($details['created_movie_links'], $sourceIds, (int) $merge->to_performer_id);

            $merge->reversed_at = now()->toDateTimeString();
            $merge->save();

            return PerformerIdentityEvent::query()->create([
                'event_type' => 'split',
                'from_performer_id' => $merge->to_performer_id,
                'to_performer_id' => $merge->from_performer_id,
                'related_event_id' => $merge->id,
                'details' => ['original_merge_event_id' => (int) $merge->id],
                'created_at' => now(),
            ]);
        });

        $this->reindexPerformers([(int) $merge->from_performer_id, (int) $merge->to_performer_id]);

        return $split;
    }

    public function undoSplit(int $splitEventId): PerformerIdentityEvent
    {
        $split = PerformerIdentityEvent::query()
            ->whereKey($splitEventId)
            ->where('event_type', 'split')
            ->whereNull('reversed_at')
            ->first();
        if (! $split instanceof PerformerIdentityEvent) {
            throw new RuntimeException('An active split event was not found.');
        }

        $merge = PerformerIdentityEvent::query()->find((int) $split->related_event_id);
        if (! $merge instanceof PerformerIdentityEvent) {
            throw new RuntimeException('The split event has no merge event to restore.');
        }
        $details = $this->mergeDetails($merge);
        $this->assertSplitCanBeUndone($merge, $details);

        $undo = DB::transaction(function () use ($split, $merge, $details): PerformerIdentityEvent {
            DB::table('performer_sources')->whereIn('id', $details['source_link_ids'])
                ->update(['performer_id' => $merge->to_performer_id]);

            Performer::query()->whereIn('id', $details['source_performer_ids'])
                ->update(['canonical_performer_id' => $merge->to_performer_id]);
            Performer::query()->whereKey($merge->to_performer_id)->update(['canonical_performer_id' => null]);

            $this->moveRelationOwnersToCanonical('performer_aliases', $details['alias_owners'], (int) $merge->to_performer_id);
            $this->moveRelationOwnersToCanonical('performer_media', $details['media_owners'], (int) $merge->to_performer_id);
            DB::table('performer_aliases')->whereIn('source_performer_id', $details['source_performer_ids'])
                ->update(['performer_id' => $merge->to_performer_id]);
            DB::table('performer_media')->whereIn('source_performer_id', $details['source_performer_ids'])
                ->update(['performer_id' => $merge->to_performer_id]);
            $this->copyMovieLinks($details['source_performer_ids'], (int) $merge->to_performer_id);
            foreach ($details['created_movie_links'] as $link) {
                DB::table('movie_performers')->insertOrIgnore([
                    'movie_id' => $link['movie_id'],
                    'performer_id' => $merge->to_performer_id,
                    'source_slug' => $link['source_slug'],
                    'crawled_at' => $link['crawled_at'],
                ]);
            }

            $split->reversed_at = now()->toDateTimeString();
            $split->save();
            $merge->reversed_at = null;
            $merge->save();

            return PerformerIdentityEvent::query()->create([
                'event_type' => 'undo',
                'from_performer_id' => $merge->from_performer_id,
                'to_performer_id' => $merge->to_performer_id,
                'related_event_id' => $split->id,
                'details' => ['original_merge_event_id' => (int) $merge->id],
                'created_at' => now(),
            ]);
        });

        $this->reindexPerformers([(int) $merge->from_performer_id, (int) $merge->to_performer_id]);

        return $undo;
    }

    /**
     * The crawl path only compares against roots sharing a cross-link or a normalized name; the full scan
     * (which also finds weak name matches) is reserved for the offline backfill.
     *
     * @return array{decision: string, candidate_id: int|null, reason: string|null, similarity: float, review_candidates: list<array{id: int, reason: string, similarity: float}>}
     */
    private function evaluateSource(PerformerSource $source, bool $fullScan = false): array
    {
        $currentId = (int) $source->performer_id;
        $incoming = $this->sourceIdentity($source);

        if ($fullScan) {
            $roots = Performer::query()->whereNull('canonical_performer_id')->where('id', '<>', $currentId)
                ->with(['aliases', 'sources.sourcePerformer.aliases'])->lazyById(200);
        } else {
            $candidateIds = $this->candidateRootIds($source, $incoming['names'], $currentId);
            $roots = $candidateIds === [] ? [] : Performer::query()->whereNull('canonical_performer_id')
                ->whereIn('id', $candidateIds)->orderBy('id')
                ->with(['aliases', 'sources.sourcePerformer.aliases'])->get();
        }

        $candidates = (function () use ($roots) {
            foreach ($roots as $root) {
                yield $this->rootIdentity($root);
            }
        })();

        return $this->matcher->evaluate($incoming, $candidates);
    }

    /**
     * @param list<string> $names
     * @return list<int>
     */
    private function candidateRootIds(PerformerSource $source, array $names, int $currentId): array
    {
        $ids = [];
        if ($source->cross_link_id !== null && $source->cross_link_id !== '') {
            $ids = PerformerSource::query()
                ->where('cross_link_source', (string) ($source->cross_link_source ?: $source->site_slug))
                ->where('cross_link_id', (string) $source->cross_link_id)
                ->pluck('performer_id')->map(static fn(mixed $id): int => (int) $id)->all();
        }

        $keys = $this->normalizedNames($names);
        if ($keys !== []) {
            $nameIds = DB::table('performer_name_keys as k')
                ->join('performers as p', 'p.id', '=', 'k.source_performer_id')
                ->whereIn('k.normalized_name', $keys)
                ->selectRaw('COALESCE(p.canonical_performer_id, p.id) as root_id')
                ->distinct()->pluck('root_id');
            foreach ($nameIds as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_diff(array_unique($ids), [$currentId]));
    }

    /** Stores the normalized names of a source performer so the crawl path can find candidates by index. */
    private function refreshNameKeys(PerformerSource $source): void
    {
        $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
        $keys = $this->normalizedNames($this->sourceIdentity($source)['names']);
        if ($keys === []) {
            return;
        }

        DB::table('performer_name_keys')->insertOrIgnore(array_map(
            static fn(string $key): array => ['source_performer_id' => $sourcePerformerId, 'normalized_name' => $key],
            $keys,
        ));
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private function normalizedNames(array $names): array
    {
        return array_values(array_unique(array_filter(array_map($this->matcher->normalizeName(...), $names))));
    }

    /** @return array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} */
    private function sourceIdentity(PerformerSource $source): array
    {
        $performer = $source->sourcePerformer()->first();
        $names = array_filter([
            $source->name_romaji,
            $source->name_kanji,
            $source->name_kana,
            $performer?->name_romaji,
            $performer?->name_kanji,
            $performer?->name_kana,
        ], static fn(mixed $name): bool => is_string($name) && trim($name) !== '');
        $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
        $names = array_merge($names, DB::table('performer_aliases')
            ->where('source_performer_id', $sourcePerformerId)->pluck('alias')->all());

        $birthDate = $this->dateString($source->birth_date ?? $performer?->getAttribute('birth_date'));

        return [
            'id' => (int) ($source->source_performer_id ?? $source->performer_id),
            'names' => array_values($names),
            'birth_dates' => $birthDate === null ? [] : [$birthDate],
            'cross_links' => $source->cross_link_id === null ? [] : [[
                'source' => (string) ($source->cross_link_source ?: $source->site_slug),
                'id' => (string) $source->cross_link_id,
            ]],
        ];
    }

    /** @return array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} */
    private function rootIdentity(Performer $root): array
    {
        $names = array_filter([
            $root->name_romaji,
            $root->name_kanji,
            $root->name_kana,
        ], static fn(mixed $name): bool => is_string($name) && trim($name) !== '');
        $names = array_merge($names, $root->aliases->pluck('alias')->all());
        $birthDates = [];
        $rootBirthDate = $this->dateString($root->getAttribute('birth_date'));
        if ($rootBirthDate !== null) {
            $birthDates[] = $rootBirthDate;
        }
        $crossLinks = [];

        foreach ($root->sources as $source) {
            $names = array_merge($names, array_filter([
                $source->name_romaji,
                $source->name_kanji,
                $source->name_kana,
                $source->sourcePerformer?->name_romaji,
                $source->sourcePerformer?->name_kanji,
                $source->sourcePerformer?->name_kana,
            ], static fn(mixed $name): bool => is_string($name) && trim($name) !== ''));
            if ($source->sourcePerformer instanceof Performer) {
                $names = array_merge($names, $source->sourcePerformer->aliases->pluck('alias')->all());
            }

            $date = $this->dateString($source->birth_date ?? $source->sourcePerformer?->getAttribute('birth_date'));
            if ($date !== null) {
                $birthDates[] = $date;
            }
            if (is_string($source->cross_link_id) && $source->cross_link_id !== '') {
                $crossLinks[] = [
                    'source' => (string) ($source->cross_link_source ?: $source->site_slug),
                    'id' => $source->cross_link_id,
                ];
            }
        }

        return [
            'id' => (int) $root->id,
            'names' => array_values(array_unique($names)),
            'birth_dates' => array_values(array_unique($birthDates)),
            'cross_links' => $crossLinks,
        ];
    }

    /** @param array<string, mixed> $evaluation */
    private function mergeRoots(int $fromId, int $toId, array $evaluation): void
    {
        if ($fromId === $toId) {
            return;
        }

        $details = DB::transaction(function () use ($fromId, $toId, $evaluation): array {
            $from = Performer::query()->lockForUpdate()->findOrFail($fromId);
            $to = Performer::query()->lockForUpdate()->findOrFail($toId);
            if ($from->canonical_performer_id !== null || $to->canonical_performer_id !== null) {
                return [];
            }

            $sourceIds = Performer::query()->where('canonical_performer_id', $fromId)->pluck('id')->map(fn(mixed $id): int => (int) $id)->all();
            $sourceIds[] = $fromId;
            $sourceIds = array_values(array_unique($sourceIds));
            $sourceLinkIds = PerformerSource::query()->where('performer_id', $fromId)->pluck('id')->map(fn(mixed $id): int => (int) $id)->all();
            $aliasOwners = $this->relationOwners('performer_aliases', $sourceIds);
            $mediaOwners = $this->relationOwners('performer_media', $sourceIds);
            $createdMovieLinks = $this->copyMovieLinks($sourceIds, $toId);

            DB::table('performer_sources')->whereIn('id', $sourceLinkIds)->update(['performer_id' => $toId]);
            Performer::query()->whereIn('id', $sourceIds)->where('id', '<>', $toId)
                ->update(['canonical_performer_id' => $toId]);
            DB::table('performer_aliases')->whereIn('id', array_column($aliasOwners, 'id'))->update(['performer_id' => $toId]);
            DB::table('performer_media')->whereIn('id', array_column($mediaOwners, 'id'))->update(['performer_id' => $toId]);

            $eventDetails = [
                'source_performer_ids' => $sourceIds,
                'source_link_ids' => $sourceLinkIds,
                'alias_owners' => $aliasOwners,
                'media_owners' => $mediaOwners,
                'created_movie_links' => $createdMovieLinks,
                'reason' => $evaluation['reason'] ?? 'backfill',
                'similarity' => $evaluation['similarity'] ?? 1.0,
            ];

            PerformerIdentityEvent::query()->create([
                'event_type' => 'merge',
                'from_performer_id' => $fromId,
                'to_performer_id' => $toId,
                'details' => $eventDetails,
                'created_at' => now(),
            ]);

            return $eventDetails;
        });

        if ($details !== []) {
            $this->reindexPerformers([$fromId, $toId]);
        }
    }

    /** @param list<int> $performerIds @return list<array{id: int, performer_id: int}> */
    private function relationOwners(string $table, array $performerIds): array
    {
        return DB::table($table)->whereIn('performer_id', $performerIds)->get(['id', 'performer_id'])
            ->map(static fn(object $row): array => ['id' => (int) $row->id, 'performer_id' => (int) $row->performer_id])
            ->all();
    }

    /** @param list<int> $sourceIds @return list<array{movie_id: int, source_slug: string, crawled_at: string}> */
    private function copyMovieLinks(array $sourceIds, int $targetId): array
    {
        $created = [];
        foreach (DB::table('movie_performers')->whereIn('performer_id', $sourceIds)->get() as $link) {
            $exists = DB::table('movie_performers')->where('movie_id', $link->movie_id)
                ->where('performer_id', $targetId)->exists();
            if ($exists) {
                continue;
            }

            DB::table('movie_performers')->insert([
                'movie_id' => $link->movie_id,
                'performer_id' => $targetId,
                'source_slug' => $link->source_slug,
                'crawled_at' => $link->crawled_at,
            ]);
            $created[] = [
                'movie_id' => (int) $link->movie_id,
                'source_slug' => (string) $link->source_slug,
                'crawled_at' => (string) $link->crawled_at,
            ];
        }

        return $created;
    }

    /** @param list<array{id: int, performer_id: int}> $owners */
    private function restoreRelationOwners(string $table, array $owners): void
    {
        foreach ($owners as $owner) {
            DB::table($table)->where('id', $owner['id'])->update(['performer_id' => $owner['performer_id']]);
        }
    }

    /** @param list<array{id: int, performer_id: int}> $owners */
    private function moveRelationOwnersToCanonical(string $table, array $owners, int $canonicalId): void
    {
        foreach ($owners as $owner) {
            DB::table($table)->where('id', $owner['id'])->update(['performer_id' => $canonicalId]);
        }
    }

    /** @param list<array{movie_id: int, source_slug: string, crawled_at: string}> $links @param list<int> $splitIds */
    private function removeCreatedMovieLinks(array $links, array $splitIds, int $canonicalId): void
    {
        foreach ($links as $link) {
            $remainingIds = Performer::query()->where('canonical_performer_id', $canonicalId)
                ->whereNotIn('id', $splitIds)->pluck('id')->all();
            $stillLinked = DB::table('movie_performers')->where('movie_id', $link['movie_id'])
                ->whereIn('performer_id', $remainingIds)->exists();
            if (! $stillLinked) {
                DB::table('movie_performers')->where('movie_id', $link['movie_id'])
                    ->where('performer_id', $canonicalId)->delete();
            }
        }
    }

    /** @param array<string, mixed> $review */
    private function recordReview(array $review): void
    {
        DB::table('performer_identity_reviews')->updateOrInsert(
            [
                'source_performer_id' => $review['source_performer_id'],
                'candidate_performer_id' => $review['candidate_performer_id'],
                'status' => 'open',
            ],
            static fn(bool $exists): array => [
                'reason' => $review['reason'],
                'similarity' => $review['similarity'],
                'resolved_at' => null,
                ...($exists ? [] : ['created_at' => now()]),
            ],
        );
        Performer::query()->whereKey($review['source_performer_id'])->update(['needs_review' => true]);
        Performer::query()->whereKey($review['candidate_performer_id'])->update(['needs_review' => true]);
    }

    /** @param array<string, mixed> $details */
    private function assertMergeCanBeSplit(PerformerIdentityEvent $merge, array $details): void
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
    private function assertSplitCanBeUndone(PerformerIdentityEvent $merge, array $details): void
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

    /** @return list<PerformerSource> */
    private function ensureSourceLinks(bool $dryRun): array
    {
        $missing = [];
        $unlinked = Performer::query()->whereNotExists(static function ($query): void {
            $query->selectRaw('1')->from('performer_sources')
                ->whereColumn('performer_sources.source_performer_id', 'performers.id');
        })->orderBy('id');

        foreach ($unlinked->get() as $performer) {
            $attributes = [
                'performer_id' => $performer->canonical_performer_id ?? $performer->id,
                'source_performer_id' => $performer->id,
                'site_slug' => $performer->source_slug,
                'mongo_id' => 'local:' . $performer->id,
                'external_id' => $performer->external_id,
                'source_url' => $performer->profile_url,
                'name_romaji' => $performer->name_romaji,
                'name_kanji' => $performer->name_kanji,
                'name_kana' => $performer->name_kana,
                'birth_date' => $this->dateString($performer->getAttribute('birth_date')),
                'crawled_at' => $performer->crawled_at ?? now(),
            ];

            if ($dryRun) {
                $missing[] = new PerformerSource($attributes);
            } else {
                PerformerSource::query()->firstOrCreate(
                    ['site_slug' => $attributes['site_slug'], 'mongo_id' => $attributes['mongo_id']],
                    $attributes,
                );
            }
        }

        return $dryRun ? $missing : [];
    }

    /** @param list<int> $performerIds */
    private function reindexPerformers(array $performerIds): void
    {
        foreach (array_unique($performerIds) as $performerId) {
            $performer = Performer::query()->whereKey($performerId)
                ->with(['aliases', 'sources'])
                ->first();
            if ($performer instanceof Performer && $performer->canonical_performer_id === null) {
                $this->indexer->indexPerformer($performer);
            } elseif ($performer instanceof Performer) {
                $this->indexer->forgetPerformer($performer);
            }
        }
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
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
    private function mergeDetails(PerformerIdentityEvent $event): array
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
