<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerAlias;
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
        private readonly PerformerMergeGuard $mergeGuard,
    ) {}

    /** Lowest review similarity for which the trigram block is guaranteed to contain every reviewable pair. */
    private const MIN_BLOCKED_REVIEW_SIMILARITY = 0.82;

    private int $candidateComparisons = 0;

    /** Number of source-to-root comparisons the last backfill handed to the matcher. */
    public function candidateComparisons(): int
    {
        return $this->candidateComparisons;
    }

    /**
     * Works from one in-memory snapshot of the catalog, so the cost is a fixed number of chunked reads plus
     * the matcher comparisons, not a set of queries for every source row.
     *
     * @return array{merges: list<array<string, mixed>>, reviews: list<array<string, mixed>>}
     */
    public function backfill(bool $dryRun = false): array
    {
        $this->candidateComparisons = 0;
        $snapshot = $this->loadSnapshot($this->ensureSourceLinks($dryRun));
        if (! $dryRun) {
            $this->storeNameKeys($snapshot);
        }

        $merges = [];
        $reviews = [];
        $plannedReviews = [];
        $plannedPairs = [];

        foreach ($snapshot['sources'] as $source) {
            if ($source['removed_at'] !== null) {
                continue;
            }
            $sourcePerformerId = (int) ($source['source_performer_id'] ?? $source['performer_id']);
            $currentPerformerId = (int) $source['performer_id'];
            if ($sourcePerformerId !== $currentPerformerId
                || ($snapshot['root_of'][$currentPerformerId] ?? $currentPerformerId) !== $currentPerformerId) {
                continue;
            }

            $incoming = $this->snapshotSourceIdentity($snapshot, $source);
            $candidates = $this->boundedCandidates($snapshot, $incoming, $currentPerformerId);
            $this->candidateComparisons += count($candidates);
            $evaluation = $this->matcher->evaluate($incoming, $candidates);
            if ($evaluation['decision'] === 'merge') {
                $candidateId = (int) $evaluation['candidate_id'];
                $fromId = max($currentPerformerId, $candidateId);
                $toId = min($currentPerformerId, $candidateId);
                $pair = $fromId . ':' . $toId;
                if (isset($plannedPairs[$pair])) {
                    continue;
                }
                $plannedPairs[$pair] = true;
                $merges[] = [
                    'source_performer_id' => $sourcePerformerId,
                    'from_performer_id' => $fromId,
                    'to_performer_id' => $toId,
                    'reason' => $evaluation['reason'],
                ];

                if (! $dryRun && $this->mergeRoots($fromId, $toId, $evaluation)) {
                    $this->foldRootIntoSnapshot($snapshot, $fromId, $toId);
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
        $this->refreshNameKeys($source);

        // A concurrent merge can make a planned merge a no-op; re-evaluate against the new roots a few times.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $currentId = $this->canonicalId((int) $source->performer_id);
            $evaluation = $this->evaluateSource($source);
            if ($evaluation['decision'] !== 'merge') {
                break;
            }

            $candidateId = (int) $evaluation['candidate_id'];
            $fromId = max($currentId, $candidateId);
            $toId = min($currentId, $candidateId);
            if ($this->mergeRoots($fromId, $toId, $evaluation)) {
                return $toId;
            }

            $source->refresh();
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

        return $this->canonicalId((int) $source->performer_id);
    }

    /** Reconciles a freshly persisted source record and moves its aliases and media to the canonical performer. */
    public function reconcileAndSync(PerformerSource $source): int
    {
        $canonicalId = $this->reconcileSource($source);
        $this->syncSourceContributions($source->refresh());
        $this->reindexPerformers([$canonicalId]);

        return $canonicalId;
    }

    /** @param list<string|null> $currentNames the incoming romaji, kanji and kana names, in that field order */
    public function preserveRenamedSourceNames(PerformerSource $source, Performer $performer, array $currentNames, string $siteSlug): void
    {
        $sourcePerformerId = (int) ($source->source_performer_id ?? $performer->id);
        $canonicalId = (int) ($performer->canonical_performer_id ?? $performer->id);

        foreach ([$source->name_romaji, $source->name_kanji, $source->name_kana] as $position => $previousName) {
            $current = $currentNames[$position] ?? null;
            // A field renamed in place is compared by position; an absent incoming value keeps the stored one.
            if (! is_string($previousName) || trim($previousName) === '' || ! is_string($current)
                || trim($current) === '' || trim($current) === trim($previousName)) {
                continue;
            }

            PerformerAlias::query()->firstOrCreate(
                [
                    'performer_id' => $canonicalId,
                    'alias' => trim($previousName),
                    'source_slug' => $siteSlug,
                    'source_performer_id' => $sourcePerformerId,
                ],
                ['kind' => 'renamed'],
            );
        }
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

        $details = $this->mergeGuard->mergeDetails($merge);
        $this->mergeGuard->assertMergeCanBeSplit($merge, $details);
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
        $details = $this->mergeGuard->mergeDetails($merge);
        $this->mergeGuard->assertSplitCanBeUndone($merge, $details);

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
     * The crawl path only compares against roots sharing a cross-link or a normalized name; the backfill
     * (which also finds weak name matches) compares against its in-memory snapshot of every root.
     *
     * @return array{decision: string, candidate_id: int|null, reason: string|null, similarity: float, review_candidates: list<array{id: int, reason: string, similarity: float}>}
     */
    private function evaluateSource(PerformerSource $source): array
    {
        $currentId = (int) $source->performer_id;
        $incoming = $this->sourceIdentity($source);
        $candidateIds = $this->candidateRootIds($source, $incoming['names'], $currentId);
        $roots = $candidateIds === [] ? [] : Performer::query()->whereNull('canonical_performer_id')
            ->whereIn('id', $candidateIds)->orderBy('id')
            ->with(['aliases', 'sources.sourcePerformer.aliases'])->get();

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
    private function mergeRoots(int $fromId, int $toId, array $evaluation): bool
    {
        if ($fromId === $toId) {
            return false;
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

        return $details !== [];
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
        $remainingIds = Performer::query()->where('canonical_performer_id', $canonicalId)
            ->whereNotIn('id', $splitIds)->pluck('id')->all();
        $ownSlug = Performer::query()->whereKey($canonicalId)->value('source_slug');
        $mergeCopies = [];
        foreach ($links as $link) {
            $mergeCopies[$link['movie_id']] = $link;
        }
        $splitMovieIds = DB::table('movie_performers')->whereIn('performer_id', $splitIds)->pluck('movie_id')
            ->map(static fn(mixed $id): int => (int) $id)->all();

        foreach (array_unique(array_merge(array_keys($mergeCopies), $splitMovieIds)) as $movieId) {
            $stillLinked = DB::table('movie_performers')->where('movie_id', $movieId)
                ->whereIn('performer_id', $remainingIds)->exists();
            $canonicalLink = DB::table('movie_performers')->where('movie_id', $movieId)
                ->where('performer_id', $canonicalId)->first();
            if ($stillLinked || $canonicalLink === null) {
                continue;
            }

            // The canonical row is the merge copy, or a later crawl of a split source wrote the same values to it.
            $copy = $mergeCopies[$movieId] ?? null;
            $isMergeCopy = $copy !== null && $canonicalLink->source_slug === $copy['source_slug']
                && (string) $canonicalLink->crawled_at === $copy['crawled_at'];
            $isSplitSourceWrite = $canonicalLink->source_slug !== $ownSlug
                && DB::table('movie_performers')->where('movie_id', $movieId)
                    ->whereIn('performer_id', $splitIds)
                    ->where('source_slug', $canonicalLink->source_slug)
                    ->where('crawled_at', $canonicalLink->crawled_at)->exists();
            if ($isMergeCopy || $isSplitSourceWrite) {
                DB::table('movie_performers')->where('movie_id', $movieId)
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

    /**
     * Creates the missing source links in batches; a dry run only returns what would be created.
     *
     * @return list<array<string, mixed>>
     */
    private function ensureSourceLinks(bool $dryRun): array
    {
        $missing = [];
        Performer::query()->whereNotExists(static function ($query): void {
            $query->selectRaw('1')->from('performer_sources')
                ->whereColumn('performer_sources.source_performer_id', 'performers.id');
        })->chunkById(500, function ($performers) use ($dryRun, &$missing): void {
            $rows = [];
            foreach ($performers as $performer) {
                $rows[] = [
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
                    'crawled_at' => ($performer->crawled_at ?? now())->toDateTimeString(),
                    'removed_at' => null,
                ];
            }

            if ($dryRun) {
                array_push($missing, ...$rows);
            } else {
                DB::table('performer_sources')->insertOrIgnore($rows);
            }
        });

        return $missing;
    }

    /**
     * @param list<array<string, mixed>> $extraSources planned source rows that are not stored yet (dry run)
     * @return array<string, mixed>
     */
    private function loadSnapshot(array $extraSources): array
    {
        $performers = [];
        $canonicalOf = [];
        $columns = ['id', 'canonical_performer_id', 'name_romaji', 'name_kanji', 'name_kana', 'birth_date'];
        foreach (DB::table('performers')->select($columns)->lazyById(1000) as $row) {
            $performers[(int) $row->id] = [
                'names' => $this->presentStrings([$row->name_romaji, $row->name_kanji, $row->name_kana]),
                'birth' => $this->dateString($row->birth_date),
            ];
            $canonicalOf[(int) $row->id] = $row->canonical_performer_id === null ? null : (int) $row->canonical_performer_id;
        }

        $ownerAliases = [];
        $sourceAliases = [];
        foreach (DB::table('performer_aliases')->select(['id', 'performer_id', 'source_performer_id', 'alias'])->lazyById(2000) as $row) {
            $ownerAliases[(int) $row->performer_id][] = (string) $row->alias;
            if ($row->source_performer_id !== null) {
                $sourceAliases[(int) $row->source_performer_id][] = (string) $row->alias;
            }
        }

        $sourceColumns = [
            'id', 'performer_id', 'source_performer_id', 'site_slug', 'name_romaji', 'name_kanji', 'name_kana',
            'birth_date', 'cross_link_source', 'cross_link_id', 'removed_at',
        ];
        $sources = [];
        foreach (DB::table('performer_sources')->select($sourceColumns)->lazyById(1000) as $row) {
            $sources[] = (array) $row;
        }
        foreach ($extraSources as $extra) {
            $sources[] = $extra + ['id' => null, 'cross_link_source' => null, 'cross_link_id' => null];
        }

        $rootOf = [];
        $members = [];
        foreach ($canonicalOf as $id => $parent) {
            $root = $id;
            for ($hops = 0; $parent !== null && $hops < 50; $hops++) {
                $root = $parent;
                $parent = $canonicalOf[$parent] ?? null;
            }
            $rootOf[$id] = $root;
            $members[$root][] = $id;
        }

        $roots = [];
        foreach ($performers as $id => $performer) {
            if ($canonicalOf[$id] !== null) {
                continue;
            }
            $roots[$id] = [
                'id' => $id,
                'names' => array_merge($performer['names'], $ownerAliases[$id] ?? []),
                'birth_dates' => $performer['birth'] === null ? [] : [$performer['birth']],
                'cross_links' => [],
            ];
        }

        $snapshot = [
            'performers' => $performers,
            'sources' => $sources,
            'source_aliases' => $sourceAliases,
            'roots' => $roots,
            'root_of' => $rootOf,
            'members' => $members,
        ];

        foreach ($sources as $source) {
            $rootId = (int) $source['performer_id'];
            if (! isset($snapshot['roots'][$rootId])) {
                continue;
            }
            $identity = $this->snapshotSourceIdentity($snapshot, $source);
            $root = &$snapshot['roots'][$rootId];
            $root['names'] = array_merge($root['names'], $identity['names']);
            $root['birth_dates'] = array_merge($root['birth_dates'], $identity['birth_dates']);
            $root['cross_links'] = array_merge($root['cross_links'], $identity['cross_links']);
            unset($root);
        }
        foreach ($snapshot['roots'] as $rootId => $identity) {
            $snapshot['roots'][$rootId]['names'] = array_values(array_unique($identity['names']));
            $snapshot['roots'][$rootId]['birth_dates'] = array_values(array_unique($identity['birth_dates']));
        }
        $snapshot['index'] = [];
        foreach ($snapshot['roots'] as $rootId => $identity) {
            $this->indexRoot($snapshot, $rootId, $identity);
        }

        return $snapshot;
    }

    /**
     * Blocking keys of an identity: each normalized name, each namespaced cross-link and each name trigram, so
     * a candidate must share at least one before it is compared. Names that share none cannot reach the
     * configured review similarity, which keeps the comparisons per source bounded by the block sizes.
     *
     * @param array{names: list<string>, cross_links: list<array{source: string, id: string}>} $identity
     * @return list<string>
     */
    private function blockingKeys(array $identity): array
    {
        $keys = [];
        foreach ($identity['cross_links'] as $link) {
            $keys['c:' . $link['source'] . ':' . $link['id']] = true;
        }
        foreach ($this->normalizedNames($identity['names']) as $name) {
            $keys['n:' . $name] = true;
            $padded = ' ' . $name . ' ';
            $characters = preg_split('//u', $padded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($i = 0, $last = count($characters) - 3; $i <= $last; $i++) {
                $keys['g:' . $characters[$i] . $characters[$i + 1] . $characters[$i + 2]] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /** @param array<string, mixed> $snapshot @param array<string, mixed> $identity */
    private function indexRoot(array &$snapshot, int $rootId, array $identity): void
    {
        foreach ($this->blockingKeys($identity) as $key) {
            $snapshot['index'][$key][$rootId] = true;
        }
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $incoming
     * @return list<array<string, mixed>>
     */
    private function boundedCandidates(array $snapshot, array $incoming, int $excludeRootId): array
    {
        if ((float) config('performer_identity.review_name_similarity', 0.82) < self::MIN_BLOCKED_REVIEW_SIMILARITY) {
            // Below this similarity names can be reviewable without sharing any trigram, so the block cannot
            // be trusted: compare against every root instead of omitting configured reviews.
            return array_values(array_filter(
                $snapshot['roots'],
                static fn(array $root, int|string $rootId): bool => (int) $rootId !== $excludeRootId,
                ARRAY_FILTER_USE_BOTH,
            ));
        }

        $candidates = [];
        foreach ($this->blockingKeys($incoming) as $key) {
            foreach (array_keys($snapshot['index'][$key] ?? []) as $rootId) {
                if ($rootId !== $excludeRootId && isset($snapshot['roots'][$rootId])) {
                    $candidates[$rootId] = $snapshot['roots'][$rootId];
                }
            }
        }

        return array_values($candidates);
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $source
     * @return array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>}
     */
    private function snapshotSourceIdentity(array $snapshot, array $source): array
    {
        $sourcePerformerId = (int) ($source['source_performer_id'] ?? $source['performer_id']);
        $performer = $source['source_performer_id'] === null ? null : ($snapshot['performers'][$sourcePerformerId] ?? null);
        $names = array_merge(
            $this->presentStrings([$source['name_romaji'], $source['name_kanji'], $source['name_kana']]),
            $performer['names'] ?? [],
            $snapshot['source_aliases'][$sourcePerformerId] ?? [],
        );
        $birthDate = $this->dateString($source['birth_date'] ?? null) ?? ($performer['birth'] ?? null);
        $crossLinkId = $source['cross_link_id'] ?? null;

        return [
            'id' => $sourcePerformerId,
            'names' => $names,
            'birth_dates' => $birthDate === null ? [] : [$birthDate],
            'cross_links' => ! is_string($crossLinkId) || $crossLinkId === '' ? [] : [[
                'source' => (string) (($source['cross_link_source'] ?? null) ?: $source['site_slug']),
                'id' => $crossLinkId,
            ]],
        ];
    }

    /** @param array<string, mixed> $snapshot */
    private function foldRootIntoSnapshot(array &$snapshot, int $fromId, int $toId): void
    {
        if (isset($snapshot['roots'][$fromId], $snapshot['roots'][$toId])) {
            $from = $snapshot['roots'][$fromId];
            $to = &$snapshot['roots'][$toId];
            $to['names'] = array_values(array_unique(array_merge($to['names'], $from['names'])));
            $to['birth_dates'] = array_values(array_unique(array_merge($to['birth_dates'], $from['birth_dates'])));
            $to['cross_links'] = array_merge($to['cross_links'], $from['cross_links']);
            unset($to);
            $this->indexRoot($snapshot, $toId, $from);
        }
        unset($snapshot['roots'][$fromId]);

        foreach ($snapshot['members'][$fromId] ?? [$fromId] as $memberId) {
            $snapshot['root_of'][$memberId] = $toId;
            $snapshot['members'][$toId][] = $memberId;
        }
        unset($snapshot['members'][$fromId]);
    }

    /** Writes the normalized-name keys of every stored source performer in batches. @param array<string, mixed> $snapshot */
    private function storeNameKeys(array $snapshot): void
    {
        $rows = [];
        foreach ($snapshot['sources'] as $source) {
            if ($source['id'] === null) {
                continue;
            }
            $identity = $this->snapshotSourceIdentity($snapshot, $source);
            foreach ($this->normalizedNames($identity['names']) as $key) {
                $rows[$identity['id'] . ':' . $key] = ['source_performer_id' => $identity['id'], 'normalized_name' => $key];
            }
            if (count($rows) >= 500) {
                DB::table('performer_name_keys')->insertOrIgnore(array_values($rows));
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('performer_name_keys')->insertOrIgnore(array_values($rows));
        }
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    private function presentStrings(array $values): array
    {
        return array_values(array_filter($values, static fn(mixed $value): bool => is_string($value) && trim($value) !== ''));
    }

    /**
     * Indexes after the outermost transaction commits, so a rolled-back crawl never reaches Elasticsearch.
     *
     * @param list<int> $performerIds
     */
    private function reindexPerformers(array $performerIds): void
    {
        $performerIds = array_values(array_unique($performerIds));
        DB::afterCommit(function () use ($performerIds): void {
            foreach ($performerIds as $performerId) {
                $performer = Performer::query()->whereKey($performerId)
                    ->with(['aliases', 'sources'])
                    ->first();
                if ($performer instanceof Performer && $performer->canonical_performer_id === null) {
                    $this->indexer->indexPerformer($performer);
                } elseif ($performer instanceof Performer) {
                    $this->indexer->forgetPerformer($performer);
                }
            }
        });
    }

    private function dateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return is_string($value) && $value !== '' ? substr($value, 0, 10) : null;
    }
}
