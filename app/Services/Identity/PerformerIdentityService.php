<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\Performer;
use App\Models\PerformerAlias;
use App\Models\PerformerIdentityEvent;
use App\Models\PerformerSource;
use App\Services\Search\ElasticsearchIndexer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    /** Review candidates written per statement batch. */
    private const REVIEW_PAGE_SIZE = 500;

    private int $candidateComparisons = 0;

    /** @var array<int, list<string>> Aliases of the current backfill batch, keyed by source performer id. */
    private array $preloadedAliases = [];

    /** Number of source-to-root comparisons the last backfill handed to the matcher. */
    public function candidateComparisons(): int
    {
        return $this->candidateComparisons;
    }

    /**
     * Streams the source records in id order and finds each one's candidates through the name indexes, so
     * memory stays flat however large the catalog is. A dry run executes in a transaction that is rolled
     * back, which makes its report exactly what a real run would do.
     *
     * @return array{merges: list<array<string, mixed>>, reviews: list<array<string, mixed>>}
     */
    public function backfill(bool $dryRun = false): array
    {
        $merges = [];
        $reviews = [];
        $this->streamBackfill($dryRun, static function (string $kind, array $row) use (&$merges, &$reviews): void {
            if ($kind === 'merge') {
                $merges[] = $row;
            } else {
                $reviews[] = $row;
            }
        });

        return ['merges' => $merges, 'reviews' => $reviews];
    }

    /**
     * Same pass as backfill() but hands every merge and review to $onRow(kind, row) instead of collecting
     * them, so the report never has to fit in memory.
     *
     * @param callable(string, array<string, mixed>): void $onRow
     * @return array{merges: int, reviews: int}
     */
    public function streamBackfill(bool $dryRun, callable $onRow): array
    {
        if (! $dryRun) {
            return $this->runBackfill($dryRun, $onRow);
        }

        DB::beginTransaction();
        try {
            return $this->runBackfill($dryRun, $onRow);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param callable(string, array<string, mixed>): void $onRow
     * @return array{merges: int, reviews: int}
     */
    private function runBackfill(bool $dryRun, callable $onRow): array
    {
        $this->candidateComparisons = 0;
        $this->ensureSourceLinks();
        $this->storeNameKeys();

        $counts = ['merges' => 0, 'reviews' => 0];

        foreach ($this->activeSources() as $source) {
            $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
            $currentPerformerId = (int) $source->performer_id;
            if ($sourcePerformerId !== $currentPerformerId
                || ! Performer::query()->whereKey($currentPerformerId)->whereNull('canonical_performer_id')->exists()) {
                continue;
            }

            $incoming = $this->sourceIdentity($source);
            $evaluation = $this->matcher->evaluate(
                $incoming,
                fn(): \Generator => $this->backfillCandidates($source, $incoming, $currentPerformerId),
            );
            // Reviews are streamed and recorded before any merge changes the roots they point at; a single
            // strong match still reports its other ambiguous candidates.
            // The review rows deduplicate a pair seen from both sides; the dry run writes them too, inside the
            // transaction it rolls back.
            $this->recordReviewBatches(
                $sourcePerformerId,
                $evaluation['review_candidates'],
                true,
                function (array $review) use (&$counts, $onRow): void {
                    $counts['reviews']++;
                    $onRow('review', $review);
                },
            );

            if ($evaluation['decision'] === 'merge') {
                $candidateId = (int) $evaluation['candidate_id'];
                $fromId = max($currentPerformerId, $candidateId);
                $toId = min($currentPerformerId, $candidateId);
                $counts['merges']++;
                $onRow('merge', [
                    'source_performer_id' => $sourcePerformerId,
                    'from_performer_id' => $fromId,
                    'to_performer_id' => $toId,
                    'reason' => $evaluation['reason'],
                ]);

                // The merged-away root is no longer a candidate or a source root, so a pair cannot repeat.
                $this->mergeRoots($fromId, $toId, $evaluation);
            }
        }

        return $counts;
    }

    public function reconcileSource(PerformerSource $source): int
    {
        $source->refresh();
        $this->refreshNameKeys($source);

        // A concurrent merge can make a planned merge a no-op; re-evaluate against the new roots a few times.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $currentId = $this->canonicalId((int) $source->performer_id);
            $evaluation = $this->evaluateSource($source);
            $this->recordReviews($source, $evaluation);
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
        $split = DB::transaction(function () use ($mergeEventId): PerformerIdentityEvent {
            $merge = PerformerIdentityEvent::query()->lockForUpdate()
                ->whereKey($mergeEventId)
                ->where('event_type', 'merge')
                ->whereNull('reversed_at')
                ->first();
            if (! $merge instanceof PerformerIdentityEvent) {
                throw new RuntimeException('An active merge event was not found.');
            }

            $details = $this->mergeGuard->mergeDetails($merge);
            // Lock the group in id order (as mergeRoots and undoSplit do) so a concurrent merge or ingest waits
            // and the group is revalidated against the committed state.
            Performer::query()->whereIn('id', array_unique([
                ...$details['source_performer_ids'],
                (int) $merge->from_performer_id,
                (int) $merge->to_performer_id,
            ]))->orderBy('id')->lockForUpdate()->get(['id']);
            $this->mergeGuard->assertMergeCanBeSplit($merge, $details);

            // Links added to the group after the merge snapshot follow the split-off source performers, so
            // select them live instead of using only the recorded ids.
            DB::table('performer_sources')
                ->where('performer_id', $merge->to_performer_id)
                ->where(static function ($query) use ($details): void {
                    $query->whereIn('id', $details['source_link_ids'])
                        ->orWhereIn('source_performer_id', $details['source_performer_ids']);
                })
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

        $this->reindexPerformers([(int) $split->to_performer_id, (int) $split->from_performer_id]);

        return $split;
    }

    public function undoSplit(int $splitEventId): PerformerIdentityEvent
    {
        $undo = DB::transaction(function () use ($splitEventId): PerformerIdentityEvent {
            $split = PerformerIdentityEvent::query()->lockForUpdate()
                ->whereKey($splitEventId)
                ->where('event_type', 'split')
                ->whereNull('reversed_at')
                ->first();
            if (! $split instanceof PerformerIdentityEvent) {
                throw new RuntimeException('An active split event was not found.');
            }

            $merge = PerformerIdentityEvent::query()->lockForUpdate()->find((int) $split->related_event_id);
            if (! $merge instanceof PerformerIdentityEvent) {
                throw new RuntimeException('The split event has no merge event to restore.');
            }
            $details = $this->mergeGuard->mergeDetails($merge);
            // Lock the whole group in id order so a concurrent merge or ingest waits and is revalidated below.
            Performer::query()->whereIn('id', array_unique([
                ...$details['source_performer_ids'],
                (int) $merge->from_performer_id,
                (int) $merge->to_performer_id,
            ]))->orderBy('id')->lockForUpdate()->get(['id']);
            $this->mergeGuard->assertSplitCanBeUndone($merge, $details);

            // Links added to the group after the merge snapshot must follow the group, so select them live.
            DB::table('performer_sources')
                ->where(static function ($query) use ($details): void {
                    $query->whereIn('id', $details['source_link_ids'])
                        ->orWhereIn('source_performer_id', $details['source_performer_ids']);
                })
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

        $this->reindexPerformers([(int) $undo->from_performer_id, (int) $undo->to_performer_id]);

        return $undo;
    }

    /** @param array{review_candidates: iterable<array{id: int, reason: string, similarity: float}>} $evaluation */
    private function recordReviews(PerformerSource $source, array $evaluation): void
    {
        $this->recordReviewBatches((int) $source->source_performer_id, $evaluation['review_candidates'], false, null);
    }

    /**
     * Records review candidates for one source in pages, so the statements depend on the page size and not on
     * the candidate count: one lookup per direction, one upsert and one flag update per page.
     *
     * @param iterable<array{id: int, reason: string, similarity: float}> $candidates
     * @param callable(array<string, mixed>): void|null $onRecorded called for each review that was written
     */
    private function recordReviewBatches(int $sourceId, iterable $candidates, bool $skipSeenFromOtherSide, ?callable $onRecorded): void
    {
        $page = [];
        foreach ($candidates as $candidate) {
            $page[(int) $candidate['id']] = $candidate;
            if (count($page) >= self::REVIEW_PAGE_SIZE) {
                $this->writeReviewPage($sourceId, $page, $skipSeenFromOtherSide, $onRecorded);
                $page = [];
            }
        }
        if ($page !== []) {
            $this->writeReviewPage($sourceId, $page, $skipSeenFromOtherSide, $onRecorded);
        }
    }

    /**
     * @param array<int, array{id: int, reason: string, similarity: float}> $page keyed by candidate id
     * @param callable(array<string, mixed>): void|null $onRecorded
     */
    private function writeReviewPage(int $sourceId, array $page, bool $skipSeenFromOtherSide, ?callable $onRecorded): void
    {
        $candidateIds = array_keys($page);
        if ($skipSeenFromOtherSide) {
            // A pair already open as (candidate, source) and not as (source, candidate) was reported from the other side.
            $forward = DB::table('performer_identity_reviews')->where('source_performer_id', $sourceId)
                ->whereIn('candidate_performer_id', $candidateIds)->where('status', 'open')
                ->pluck('candidate_performer_id')->map(static fn(mixed $id): int => (int) $id)->all();
            $reverse = DB::table('performer_identity_reviews')->where('candidate_performer_id', $sourceId)
                ->whereIn('source_performer_id', $candidateIds)->where('status', 'open')
                ->pluck('source_performer_id')->map(static fn(mixed $id): int => (int) $id)->all();
            $candidateIds = array_values(array_diff($candidateIds, array_diff($reverse, $forward)));
            if ($candidateIds === []) {
                return;
            }
        }

        $now = now();
        $rows = [];
        $reviews = [];
        foreach ($candidateIds as $candidateId) {
            $candidate = $page[$candidateId];
            $review = [
                'source_performer_id' => $sourceId,
                'candidate_performer_id' => $candidateId,
                'reason' => $candidate['reason'],
                'similarity' => $candidate['similarity'],
            ];
            $reviews[] = $review;
            $rows[] = [...$review, 'status' => 'open', 'created_at' => $now, 'resolved_at' => null];
        }

        DB::table('performer_identity_reviews')->upsert(
            $rows,
            ['source_performer_id', 'candidate_performer_id', 'status'],
            ['reason', 'similarity', 'resolved_at'],
        );
        Performer::query()->whereIn('id', [$sourceId, ...$candidateIds])->update(['needs_review' => true]);

        if ($onRecorded !== null) {
            foreach ($reviews as $review) {
                $onRecorded($review);
            }
        }
    }

    /**
     * The crawl path only compares against roots sharing a cross-link or a normalized name; the backfill
     * (which also finds weak name matches) also selects roots through the indexed name trigrams.
     *
     * @return array{decision: string, candidate_id: int|null, reason: string|null, similarity: float, review_candidates: iterable<array{id: int, reason: string, similarity: float}>}
     */
    private function evaluateSource(PerformerSource $source): array
    {
        $currentId = (int) $source->performer_id;
        $incoming = $this->sourceIdentity($source);
        $candidates = function () use ($source, $incoming, $currentId) {
            foreach ($this->candidateRootIdPages($source, $incoming['names'], $currentId, false) as $chunk) {
                $roots = Performer::query()->whereNull('canonical_performer_id')->whereIn('id', $chunk)
                    ->orderBy('id')->with(['aliases', 'sources.sourcePerformer.aliases'])->get();
                foreach ($roots as $root) {
                    yield $this->rootIdentity($root);
                }
            }
        };

        return $this->matcher->evaluate($incoming, $candidates);
    }

    /**
     * Pages of candidate root ids for the backfill: roots sharing a cross-link, a normalized name or (when
     * $withGrams) a name trigram with the source. Keyset pages keep a common trigram from materializing a catalog-sized list.
     *
     * @param list<string> $names
     * @return \Generator<int, list<int>>
     */
    private function candidateRootIdPages(PerformerSource $source, array $names, int $currentId, bool $withGrams = true): \Generator
    {
        $union = null;
        $add = static function ($query) use (&$union): void {
            $union = $union === null ? $query : $union->union($query);
        };

        if ($source->cross_link_id !== null && $source->cross_link_id !== '') {
            $add(DB::table('performer_sources as s')
                ->join('performers as p', 'p.id', '=', 's.performer_id')
                ->where('s.cross_link_source', (string) ($source->cross_link_source ?: $source->site_slug))
                ->where('s.cross_link_id', (string) $source->cross_link_id)
                ->selectRaw('COALESCE(p.canonical_performer_id, p.id) as root_id'));
        }

        $keys = $this->normalizedNames($names);
        if ($keys !== []) {
            $add(DB::table('performer_name_keys as k')
                ->join('performers as p', 'p.id', '=', 'k.source_performer_id')
                ->whereIn('k.normalized_name', $keys)
                ->selectRaw('COALESCE(p.canonical_performer_id, p.id) as root_id'));
        }

        foreach ($withGrams ? array_chunk($this->nameGrams($names), 400) : [] as $chunk) {
            $add(DB::table('performer_name_grams as g')
                ->join('performers as p', 'p.id', '=', 'g.source_performer_id')
                ->whereIn('g.gram', $chunk)
                ->selectRaw('COALESCE(p.canonical_performer_id, p.id) as root_id'));
        }

        if ($union === null) {
            return;
        }

        $last = 0;
        while (true) {
            $page = DB::query()->fromSub($union, 'c')
                ->where('c.root_id', '>', $last)->where('c.root_id', '<>', $currentId)
                ->distinct()->orderBy('c.root_id')->limit(200)->pluck('c.root_id')
                ->map(static fn(mixed $id): int => (int) $id)->all();
            if ($page === []) {
                return;
            }
            yield $page;
            $last = $page[array_key_last($page)];
        }
    }

    /** Stores the normalized names of a source performer so the crawl path can find candidates by index. */
    private function refreshNameKeys(PerformerSource $source): void
    {
        $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
        $names = $this->sourceIdentity($source)['names'];
        $keys = $this->normalizedNames($names);
        if ($keys === []) {
            return;
        }

        $this->insertKeyRows('performer_name_keys', array_map(
            static fn(string $key): array => ['source_performer_id' => $sourcePerformerId, 'normalized_name' => $key],
            $keys,
        ));
        $this->insertKeyRows('performer_name_grams', array_map(
            static fn(string $gram): array => ['source_performer_id' => $sourcePerformerId, 'gram' => $gram],
            $this->nameGrams($names),
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
        $performer = $source->sourcePerformer;
        $names = array_filter([
            $source->name_romaji,
            $source->name_kanji,
            $source->name_kana,
            $performer?->name_romaji,
            $performer?->name_kanji,
            $performer?->name_kana,
        ], static fn(mixed $name): bool => is_string($name) && trim($name) !== '');
        $sourcePerformerId = (int) ($source->source_performer_id ?? $source->performer_id);
        $names = array_merge($names, $this->preloadedAliases[$sourcePerformerId]
            ?? DB::table('performer_aliases')->where('source_performer_id', $sourcePerformerId)->pluck('alias')->all());

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
            // Lock in ascending id order, the same order undoSplit() uses, so the two cannot deadlock.
            $locked = Performer::query()->whereIn('id', [$fromId, $toId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $locked->get($fromId) ?? throw (new ModelNotFoundException())->setModel(Performer::class, [$fromId]);
            $to = $locked->get($toId) ?? throw (new ModelNotFoundException())->setModel(Performer::class, [$toId]);
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
        $lastMovie = 0;
        $lastPerformer = 0;
        while (true) {
            // Keyset pages over the composite primary key keep memory and query count bounded per 500 links.
            $page = DB::table('movie_performers')->whereIn('performer_id', $sourceIds)
                ->where(static function ($query) use ($lastMovie, $lastPerformer): void {
                    $query->where('movie_id', '>', $lastMovie)
                        ->orWhere(static fn($same) => $same->where('movie_id', $lastMovie)->where('performer_id', '>', $lastPerformer));
                })
                ->orderBy('movie_id')->orderBy('performer_id')->limit(500)->get();
            if ($page->isEmpty()) {
                break;
            }

            $last = $page->last();
            $lastMovie = (int) $last->movie_id;
            $lastPerformer = (int) $last->performer_id;

            $existing = array_flip(DB::table('movie_performers')->where('performer_id', $targetId)
                ->whereIn('movie_id', $page->pluck('movie_id')->unique()->all())->pluck('movie_id')
                ->map(static fn(mixed $id): int => (int) $id)->all());
            $rows = [];
            foreach ($page as $link) {
                $movieId = (int) $link->movie_id;
                if (isset($existing[$movieId])) {
                    continue;
                }

                $existing[$movieId] = true;
                $rows[] = [
                    'movie_id' => $movieId,
                    'performer_id' => $targetId,
                    'source_slug' => (string) $link->source_slug,
                    'crawled_at' => (string) $link->crawled_at,
                ];
                $created[] = [
                    'movie_id' => $movieId,
                    'source_slug' => (string) $link->source_slug,
                    'crawled_at' => (string) $link->crawled_at,
                ];
            }

            if ($rows !== []) {
                DB::table('movie_performers')->insert($rows);
            }
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

    /** Creates the missing source links in batches. */
    private function ensureSourceLinks(): void
    {
        Performer::query()->whereNotExists(static function ($query): void {
            $query->selectRaw('1')->from('performer_sources')
                ->whereColumn('performer_sources.source_performer_id', 'performers.id');
        })->chunkById(500, function ($performers): void {
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

            DB::table('performer_sources')->insertOrIgnore($rows);
        });
    }

    /**
     * Lazily yields the root identities that can be compared with a source: roots sharing a cross-link, a
     * normalized name or a name trigram with it. Below the trigram guarantee every root is compared.
     *
     * @param array{names: list<string>} $incoming
     * @return \Generator<int, array<string, mixed>>
     */
    private function backfillCandidates(PerformerSource $source, array $incoming, int $currentId): \Generator
    {
        $roots = Performer::query()->whereNull('canonical_performer_id')->where('id', '<>', $currentId)
            ->with(['aliases', 'sources.sourcePerformer.aliases']);
        if ((float) config('performer_identity.review_name_similarity', 0.82) < self::MIN_BLOCKED_REVIEW_SIMILARITY) {
            // Below this similarity names can be reviewable without sharing any trigram, so the block cannot
            // be trusted: compare against every root instead of omitting configured reviews.
            foreach ($roots->lazyById(200) as $root) {
                $this->candidateComparisons++;
                yield $this->rootIdentity($root);
            }

            return;
        }

        $ids = $this->candidateRootIdPages($source, $incoming['names'], $currentId);
        foreach ($ids as $chunk) {
            foreach ((clone $roots)->whereIn('id', $chunk)->orderBy('id')->get() as $root) {
                $this->candidateComparisons++;
                yield $this->rootIdentity($root);
            }
        }
    }

    /**
     * Name trigrams of the normalized names: a pair of names that can reach the review similarity shares one.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private function nameGrams(array $names): array
    {
        $grams = [];
        foreach ($this->normalizedNames($names) as $name) {
            $characters = preg_split('//u', ' ' . $name . ' ', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($i = 0, $last = count($characters) - 3; $i <= $last; $i++) {
                $grams[$characters[$i] . $characters[$i + 1] . $characters[$i + 2]] = true;
            }
        }

        return array_map('strval', array_keys($grams));
    }

    /** Writes the name keys and trigrams of every stored source performer, one chunk of sources at a time. */
    private function storeNameKeys(): void
    {
        foreach ($this->sourceBatches(PerformerSource::query()) as $sources) {
            $keys = [];
            $grams = [];
            foreach ($sources as $source) {
                $identity = $this->sourceIdentity($source);
                foreach ($this->normalizedNames($identity['names']) as $key) {
                    $keys[] = ['source_performer_id' => $identity['id'], 'normalized_name' => $key];
                }
                foreach ($this->nameGrams($identity['names']) as $gram) {
                    $grams[] = ['source_performer_id' => $identity['id'], 'gram' => $gram];
                }
            }
            $this->insertKeyRows('performer_name_keys', $keys);
            $this->insertKeyRows('performer_name_grams', $grams);
        }
        $this->preloadedAliases = [];
    }

    /** @return \Generator<int, PerformerSource> */
    private function activeSources(): \Generator
    {
        foreach ($this->sourceBatches(PerformerSource::query()->whereNull('removed_at')) as $sources) {
            yield from $sources;
        }
        $this->preloadedAliases = [];
    }

    /**
     * Reads source rows in id-ordered batches with their source performer eager-loaded and the aliases of the
     * whole batch preloaded in one query, so a pass costs a constant number of queries per batch.
     *
     * @param \Illuminate\Database\Eloquent\Builder<PerformerSource> $query
     * @return \Generator<int, \Illuminate\Database\Eloquent\Collection<int, PerformerSource>>
     */
    private function sourceBatches($query): \Generator
    {
        $lastId = 0;
        while (true) {
            $sources = (clone $query)->where('id', '>', $lastId)->orderBy('id')->limit(500)
                ->with('sourcePerformer')->get();
            if ($sources->isEmpty()) {
                return;
            }
            $ids = $sources->map(static fn(PerformerSource $source): int => (int) ($source->source_performer_id ?? $source->performer_id))
                ->unique()->values()->all();
            $this->preloadedAliases = array_fill_keys($ids, []);
            foreach (DB::table('performer_aliases')->whereIn('source_performer_id', $ids)->get(['source_performer_id', 'alias']) as $row) {
                $this->preloadedAliases[(int) $row->source_performer_id][] = $row->alias;
            }
            yield $sources;
            $lastId = (int) $sources->last()->getKey();
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function insertKeyRows(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table($table)->insertOrIgnore($chunk);
        }
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
