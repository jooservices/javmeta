<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Models\PerformerSource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Exact full-name candidates are blocked by the name index and original source credits. */
final class PerformerCoCreditRule
{
    public function __construct(private readonly PerformerIdentityMatcher $matcher) {}

    /** @return list<string> */
    public function fullNames(PerformerSource $source): array
    {
        $performer = $source->sourcePerformer;
        $names = [$source->name_romaji, $source->name_kanji, $source->name_kana,
            $performer?->name_romaji, $performer?->name_kanji, $performer?->name_kana];
        $keys = [];
        foreach ($names as $name) {
            $key = $this->matcher->normalizeName((string) $name);
            if (str_contains($key, ' ')) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function candidates(PerformerSource $source): \Generator
    {
        $keys = $this->fullNames($source);
        if ($keys === [] || ! $this->hasMovieCredit($source) || ! $this->hasCrossSiteNameCandidate($source, $keys)) {
            return;
        }
        yield from $this->candidateMatches($source, $keys);
    }

    /** @param list<string> $keys @return \Generator<int, array<string, mixed>> */
    private function candidateMatches(PerformerSource $source, array $keys): \Generator
    {
        $query = PerformerSource::query()->whereNull('removed_at')
            ->where('performer_id', '<>', $source->performer_id)
            ->where('site_slug', '<>', $source->site_slug)
            ->whereIn('source_performer_id', DB::table('performer_name_keys as k')
                ->join('performer_sources as eligible', 'eligible.source_performer_id', '=', 'k.source_performer_id')
                ->join('movie_performers as c', 'c.performer_id', '=', 'k.source_performer_id')
                ->join('movie_performers as own', 'own.movie_id', '=', 'c.movie_id')
                ->whereNull('eligible.removed_at')
                ->where('eligible.performer_id', '<>', $source->performer_id)
                ->where('eligible.site_slug', '<>', $source->site_slug)
                ->where('own.performer_id', $source->source_performer_id)
                ->where('own.source_slug', $source->site_slug)
                ->whereIn('k.normalized_name', $keys)->select('k.source_performer_id'))
            ->with('sourcePerformer');
        foreach ($query->lazyById(200) as $candidate) {
            $shared = array_values(array_intersect($keys, $this->fullNames($candidate)));
            foreach ($shared as $key) {
                $movie = $this->movies($source, $candidate, $key)->orderBy('m.id')->first();
                if ($movie === null) {
                    continue;
                }
                $conflict = $this->conflict($source, $candidate);
                yield [
                    'id' => (int) $candidate->performer_id,
                    'source_id' => (int) $source->source_performer_id,
                    'candidate_source_id' => (int) $candidate->source_performer_id,
                    'name' => $this->displayName($source),
                    'candidate_name' => $this->displayName($candidate),
                    'source' => (string) $source->site_slug,
                    'candidate_source' => (string) $candidate->site_slug,
                    'movie_id' => (int) $movie->id,
                    'movie_code' => (string) $movie->code,
                    'normalized_name' => $key,
                    'reason' => $conflict ?? 'co_credit',
                    'similarity' => 1.0,
                ];
                break;
            }
        }
    }

    /** Shared movies whose cast contains exactly one source record on each side for this name. */
    public function movies(PerformerSource $left, PerformerSource $right, string $key): Builder
    {
        $query = DB::table('movies as m')
            ->join('movie_performers as l', 'l.movie_id', '=', 'm.id')
            ->join('movie_performers as r', 'r.movie_id', '=', 'm.id')
            ->where('l.performer_id', $left->source_performer_id)
            ->where('r.performer_id', $right->source_performer_id)
            ->where('l.source_slug', $left->site_slug)
            ->where('r.source_slug', $right->site_slug)
            ->select(['m.id', 'm.display_code as code', 'l.source_slug', 'l.crawled_at']);
        foreach ([$left, $right] as $side) {
            $query->whereRaw(
                '(SELECT COUNT(DISTINCT p.id) FROM performer_name_keys k '
                . 'JOIN performers p ON p.id = k.source_performer_id '
                . 'JOIN movie_performers c ON c.performer_id = p.id '
                . 'WHERE k.normalized_name = ? AND p.source_slug = ? AND c.movie_id = m.id) = 1',
                [$key, $side->site_slug],
            );
            // Pre-deploy cast rows may lack indexes or source links: uncertainty must never look unique.
            $query->whereNotExists(static function ($unknown) use ($side): void {
                $unknown->selectRaw('1')->from('movie_performers as c')
                    ->join('performers as p', 'p.id', '=', 'c.performer_id')
                    ->whereColumn('c.movie_id', 'm.id')->where('p.source_slug', $side->site_slug)
                    ->whereNotExists(static fn($keys) => $keys->selectRaw('1')->from('performer_name_keys as k')
                        ->whereColumn('k.source_performer_id', 'p.id'));
            });
        }

        return $query;
    }

    private function displayName(PerformerSource $source): string
    {
        $performer = $source->sourcePerformer;
        foreach ([$source->name_romaji, $source->name_kanji, $source->name_kana,
            $performer?->name_romaji, $performer?->name_kanji, $performer?->name_kana] as $name) {
            if (is_string($name) && trim($name) !== '') {
                return $name;
            }
        }

        return '';
    }

    /** Reject conflicts anywhere in either canonical group, including a third source already merged. */
    private function conflict(PerformerSource $left, PerformerSource $right): ?string
    {
        $values = ['birth_date' => [], 'blood_type' => [], 'height_cm' => []];
        $sources = PerformerSource::query()->whereIn('performer_id', [$left->performer_id, $right->performer_id])
            ->with('sourcePerformer')->lazyById(200);
        foreach ($sources as $source) {
            foreach (array_keys($values) as $field) {
                $value = $source->getAttribute($field) ?? $source->sourcePerformer?->getAttribute($field);
                if ($value instanceof \DateTimeInterface) {
                    $value = $value->format('Y-m-d');
                }
                if ($value !== null && trim((string) $value) !== '') {
                    $values[$field][strtoupper(trim((string) $value))] = true;
                    if (count($values[$field]) > 1) {
                        return 'co_credit_conflicting_' . $field;
                    }
                }
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function evaluate(PerformerSource $source): array
    {
        $keys = $this->fullNames($source);
        if ($keys === [] || ! $this->hasMovieCredit($source) || ! $this->hasCrossSiteNameCandidate($source, $keys)) {
            return [
                'decision' => 'review',
                'candidate_id' => null,
                'reason' => null,
                'similarity' => 0.0,
                'co_credit' => null,
                'review_candidates' => [],
            ];
        }

        $first = null;
        $ambiguous = false;
        foreach ($this->candidateMatches($source, $keys) as $candidate) {
            if ($candidate['reason'] !== 'co_credit') {
                continue;
            }
            if ($first !== null && $first['id'] !== $candidate['id']) {
                $ambiguous = true;
            }
            $first ??= $candidate;
        }
        $merge = $first !== null && ! $ambiguous;

        return [
            'decision' => $merge ? 'merge' : 'review',
            'candidate_id' => $merge ? $first['id'] : null,
            'reason' => $merge ? 'co_credit' : null,
            'similarity' => $merge ? 1.0 : 0.0,
            'co_credit' => $merge ? $first : null,
            'review_candidates' => $this->reviews($source, $ambiguous, $keys),
        ];
    }

    private function hasMovieCredit(PerformerSource $source): bool
    {
        return DB::table('movie_performers')->where('performer_id', $source->source_performer_id)
            ->where('source_slug', $source->site_slug)->exists();
    }

    /**
     * Candidate roots must share an indexed name before movie-credit matching is useful.
     *
     * @param list<string> $keys
     */
    private function hasCrossSiteNameCandidate(PerformerSource $source, array $keys): bool
    {
        return DB::table('performer_name_keys as k')
            ->join('performer_sources as candidate', 'candidate.source_performer_id', '=', 'k.source_performer_id')
            ->whereIn('k.normalized_name', $keys)
            ->whereNull('candidate.removed_at')
            ->where('candidate.performer_id', '<>', $source->performer_id)
            ->where('candidate.site_slug', '<>', $source->site_slug)->exists();
    }

    /** @param list<string> $keys @return \Generator<int, array{id: int, reason: string, similarity: float}> */
    private function reviews(PerformerSource $source, bool $ambiguous, array $keys): \Generator
    {
        foreach ($this->candidateMatches($source, $keys) as $candidate) {
            if ($candidate['reason'] !== 'co_credit' || $ambiguous) {
                yield ['id' => $candidate['candidate_source_id'], 'reason' => $candidate['reason'] === 'co_credit' ? 'co_credit_ambiguous' : $candidate['reason'], 'similarity' => 1.0];
            }
        }
    }
}
