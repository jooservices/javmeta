<?php

declare(strict_types=1);

namespace App\Services\Identity;

/** Matches only exact normalized names with a birth date or namespaced cross-link IDs. */
final class PerformerIdentityMatcher
{
    /**
     * @param array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>} $incoming
     * @param iterable<array{id: int, names: list<string>, birth_dates: list<string>, cross_links: list<array{source: string, id: string}>}> $candidates
     * @return array{decision: string, candidate_id: int|null, reason: string|null, similarity: float, review_candidates: list<array{id: int, reason: string, similarity: float}>}
     */
    public function evaluate(array $incoming, iterable $candidates): array
    {
        $strong = [];
        $strongCount = 0;
        $review = [];

        foreach ($candidates as $candidate) {
            $similarity = $this->nameSimilarity($incoming['names'], $candidate['names']);
            $sameCrossLink = $this->sharesCrossLink($incoming['cross_links'], $candidate['cross_links']);
            $incomingBirthDates = array_values(array_unique($incoming['birth_dates']));
            $candidateBirthDates = array_values(array_unique($candidate['birth_dates']));
            $sameBirthDate = count($incomingBirthDates) === 1
                && count($candidateBirthDates) === 1
                && $incomingBirthDates[0] === $candidateBirthDates[0];

            if ($sameCrossLink || ($similarity >= (float) config('performer_identity.merge_name_similarity', 1.0)
                && $similarity === 1.0 && $sameBirthDate)) {
                $strongCount++;
                $strong[] = [
                    'id' => $candidate['id'],
                    'reason' => $sameCrossLink ? 'cross_link' : 'name_and_birth_date',
                    'similarity' => $similarity,
                ];

                continue;
            }

            $reason = null;
            if ($similarity === 1.0) {
                $reason = $sameBirthDate ? 'multiple_exact_name_matches' : 'birth_date_missing_or_conflicting';
            } elseif ($similarity >= (float) config('performer_identity.review_name_similarity', 0.82)) {
                $reason = 'weak_name_match';
            }

            if ($reason !== null) {
                $review[] = ['id' => $candidate['id'], 'reason' => $reason, 'similarity' => $similarity];
            }
        }

        // Every ambiguous candidate is reported, most similar first, so no review row is ever dropped.
        $rank = static fn(array $a, array $b): int => [$b['similarity'], $a['id']] <=> [$a['similarity'], $b['id']];
        usort($strong, $rank);
        usort($review, $rank);

        if ($strongCount === 1) {
            return [
                'decision' => 'merge',
                'candidate_id' => $strong[0]['id'],
                'reason' => $strong[0]['reason'],
                'similarity' => $strong[0]['similarity'],
                'review_candidates' => [],
            ];
        }

        if ($strongCount > 1) {
            // Strong matches come first so a full list of weaker reviews can never push them out of the report.
            $review = array_merge(array_map(
                static fn(array $match): array => [
                    'id' => $match['id'],
                    'reason' => 'multiple_strong_matches',
                    'similarity' => $match['similarity'],
                ],
                $strong,
            ), $review);
        }

        return [
            'decision' => $review === [] ? 'none' : 'review',
            'candidate_id' => null,
            'reason' => null,
            'similarity' => 0.0,
            'review_candidates' => $review,
        ];
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

        return $maximum;
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
