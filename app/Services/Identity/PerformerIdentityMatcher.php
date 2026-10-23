<?php

declare(strict_types=1);

namespace App\Services\Identity;

/** Matches only exact normalized names with a birth date or namespaced cross-link IDs. */
final class PerformerIdentityMatcher
{
    /**
     * Streams the candidates without buffering them: one pass counts the strong matches and the reviewable
     * ones, and `review_candidates` is a lazy generator that re-reads the candidates, so state stays constant
     * however many candidates one source has. Strong matches come first, then weaker reviews, each in
     * candidate order. A single strong match merges and still reports the other candidates for review.
     *
     * @param array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} $incoming
     * @param array<array-key, array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>}>|\Closure(): iterable<array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>}> $candidates
     *        An array, or a factory returning a fresh iterable each call (the matcher reads it up to three times).
     * @return array{decision: string, candidate_id: int|null, reason: string|null, similarity: float, review_candidates: iterable<array{id: int, reason: string, similarity: float}>}
     */
    public function evaluate(array $incoming, array|\Closure $candidates): array
    {
        $read = static fn(): iterable => $candidates instanceof \Closure ? $candidates() : $candidates;

        $strongCount = 0;
        $reviewCount = 0;
        $first = null;
        foreach ($read() as $candidate) {
            $match = $this->classify($incoming, $candidate);
            if ($match === null) {
                continue;
            }
            if ($match['strong']) {
                $strongCount++;
                $first ??= ['id' => $candidate['id'], 'reason' => $match['reason'], 'similarity' => $match['similarity']];
            } else {
                $reviewCount++;
            }
        }

        $merge = $strongCount === 1 && $first !== null;
        $reviews = $this->reviews($incoming, $read, $strongCount > 1);
        if ($merge) {
            return [
                'decision' => 'merge',
                'candidate_id' => $first['id'],
                'reason' => $first['reason'],
                'similarity' => $first['similarity'],
                'review_candidates' => $reviewCount > 0 ? $reviews : [],
            ];
        }

        return [
            'decision' => $strongCount + $reviewCount === 0 ? 'none' : 'review',
            'candidate_id' => null,
            'reason' => null,
            'similarity' => 0.0,
            'review_candidates' => $strongCount + $reviewCount > 0 ? $reviews : [],
        ];
    }

    /**
     * @param array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} $incoming
     * @return array{strong: bool, reason: string, similarity: float}|null
     */
    private function classify(array $incoming, array $candidate): ?array
    {
        $similarity = $this->nameSimilarity($incoming['names'], $candidate['names']);
        $sameCrossLink = $this->sharesCrossLink($incoming['cross_links'], $candidate['cross_links']);
        $incomingBirthDates = array_values(array_unique($incoming['birth_dates']));
        $candidateBirthDates = array_values(array_unique($candidate['birth_dates']));
        $sameBirthDate = count($incomingBirthDates) === 1
            && count($candidateBirthDates) === 1
            && $incomingBirthDates[0] === $candidateBirthDates[0];

        if ($sameCrossLink || ($similarity >= (float) config('performer_identity.merge_name_similarity', 1.0)
            && $similarity === 1.0 && $sameBirthDate)) {
            return [
                'strong' => true,
                'reason' => $sameCrossLink ? 'cross_link' : 'name_and_birth_date',
                'similarity' => $similarity,
            ];
        }

        if ($similarity === 1.0) {
            return [
                'strong' => false,
                'reason' => $sameBirthDate ? 'multiple_exact_name_matches' : 'birth_date_missing_or_conflicting',
                'similarity' => $similarity,
            ];
        }

        if ($similarity >= (float) config('performer_identity.review_name_similarity', 0.82)) {
            return ['strong' => false, 'reason' => 'weak_name_match', 'similarity' => $similarity];
        }

        return null;
    }

    /**
     * @param array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} $incoming
     * @return \Generator<int, array{id: int, reason: string, similarity: float}>
     */
    private function reviews(array $incoming, \Closure $read, bool $includeStrong): \Generator
    {
        if ($includeStrong) {
            foreach ($read() as $candidate) {
                $match = $this->classify($incoming, $candidate);
                if ($match !== null && $match['strong']) {
                    yield ['id' => $candidate['id'], 'reason' => 'multiple_strong_matches', 'similarity' => $match['similarity']];
                }
            }
        }

        foreach ($read() as $candidate) {
            $match = $this->classify($incoming, $candidate);
            if ($match !== null && ! $match['strong']) {
                yield ['id' => $candidate['id'], 'reason' => $match['reason'], 'similarity' => $match['similarity']];
            }
        }
    }

    public function normalizeName(string $name): string
    {
        $normalized = trim($name);
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($normalized, \Normalizer::FORM_KC) ?: $normalized;
        }

        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower($normalized, 'UTF-8')
            : strtolower($normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalized) ?? '';
        $tokens = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens, SORT_STRING);

        return implode(' ', $tokens);
    }

    /** @param list<string> $left @param list<string> $right */
    private function nameSimilarity(array $left, array $right): float
    {
        $left = array_values(array_unique(array_filter(array_map($this->normalizeName(...), $left))));
        $right = array_values(array_unique(array_filter(array_map($this->normalizeName(...), $right))));
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $maximum = 0.0;
        foreach ($left as $leftName) {
            $leftTokens = array_fill_keys(explode(' ', $leftName), true);
            foreach ($right as $rightName) {
                if ($leftName === $rightName) {
                    return 1.0;
                }

                $rightTokens = array_fill_keys(explode(' ', $rightName), true);
                $intersection = count(array_intersect_key($leftTokens, $rightTokens));
                $union = count($leftTokens + $rightTokens);
                $maximum = max($maximum, $intersection / $union);
                $maximum = max($maximum, $this->editSimilarity($leftName, $rightName));
            }
        }

        // Only identical normalized names score 1.0; token overlap alone must stay reviewable.
        return min($maximum, 0.99);
    }

    private function editSimilarity(string $left, string $right): float
    {
        $leftCharacters = preg_split('//u', $left, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $rightCharacters = preg_split('//u', $right, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $maximumLength = max(count($leftCharacters), count($rightCharacters));
        if ($maximumLength === 0) {
            return 0.0;
        }

        $previous = range(0, count($rightCharacters));
        foreach ($leftCharacters as $leftIndex => $leftCharacter) {
            $current = [$leftIndex + 1];
            foreach ($rightCharacters as $rightIndex => $rightCharacter) {
                $cost = $leftCharacter === $rightCharacter ? 0 : 1;
                $current[] = min(
                    $current[$rightIndex] + 1,
                    $previous[$rightIndex + 1] + 1,
                    $previous[$rightIndex] + $cost,
                );
            }
            $previous = $current;
        }

        return 1 - ($previous[count($rightCharacters)] / $maximumLength);
    }

    /** @param list<array{source: string, id: string}> $left @param list<array{source: string, id: string}> $right */
    private function sharesCrossLink(array $left, array $right): bool
    {
        foreach ($left as $link) {
            foreach ($right as $candidateLink) {
                if ($link['source'] === $candidateLink['source'] && $link['id'] === $candidateLink['id']) {
                    return true;
                }
            }
        }

        return false;
    }
}
