<?php

declare(strict_types=1);

namespace Tests\Unit\Tooling;

use JavMeta\Quality\PhpstanOccurrenceRatchet;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/quality/PhpstanOccurrenceRatchet.php';

final class PhpstanOccurrenceRatchetTest extends TestCase
{
    public function test_it_accepts_only_the_same_baselined_occurrence(): void
    {
        $occurrence = $this->occurrence(42, 'sha256:original-line');

        self::assertSame(
            ['unexpected' => [], 'stale' => []],
            PhpstanOccurrenceRatchet::compare([$occurrence], [$occurrence]),
        );
    }

    public function test_a_same_diagnostic_at_a_new_location_is_new_and_the_old_one_is_stale(): void
    {
        $existing = $this->occurrence(42, 'sha256:original-line');
        $replacement = $this->occurrence(57, 'sha256:replacement-line');

        $comparison = PhpstanOccurrenceRatchet::compare([$replacement], [$existing]);

        self::assertSame([$replacement + ['count' => 1]], $comparison['unexpected']);
        self::assertSame([$existing + ['count' => 1]], $comparison['stale']);
    }

    public function test_an_added_same_file_occurrence_fails_even_when_the_original_remains(): void
    {
        $existing = $this->occurrence(42, 'sha256:original-line');
        $added = $this->occurrence(57, 'sha256:added-line');

        $comparison = PhpstanOccurrenceRatchet::compare([$existing, $added], [$existing]);

        self::assertSame([$added + ['count' => 1]], $comparison['unexpected']);
        self::assertSame([], $comparison['stale']);
    }

    public function test_a_removed_finding_is_reported_as_a_stale_baseline_entry(): void
    {
        $existing = $this->occurrence(42, 'sha256:original-line');

        $comparison = PhpstanOccurrenceRatchet::compare([], [$existing]);

        self::assertSame([], $comparison['unexpected']);
        self::assertSame([$existing + ['count' => 1]], $comparison['stale']);
    }

    public function test_same_line_diagnostics_remain_distinct_by_identifier_and_message(): void
    {
        $parameterType = $this->occurrence(42, 'sha256:same-line', 'missingType.iterableValue', 'parameter has no value type');
        $returnType = $this->occurrence(42, 'sha256:same-line', 'missingType.iterableValue', 'return type has no value type');

        self::assertSame(
            ['unexpected' => [], 'stale' => []],
            PhpstanOccurrenceRatchet::compare([$parameterType, $returnType], [$parameterType, $returnType]),
        );
    }

    public function test_repeated_identical_diagnostics_keep_their_occurrence_count(): void
    {
        $occurrence = $this->occurrence(42, 'sha256:same-line');

        $comparison = PhpstanOccurrenceRatchet::compare([$occurrence, $occurrence], [$occurrence]);

        self::assertSame([$occurrence + ['count' => 1]], $comparison['unexpected']);
        self::assertSame([], $comparison['stale']);
    }

    /** @return array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string} */
    private function occurrence(
        int $line,
        string $sourceHash,
        string $identifier = 'argument.type',
        string $message = 'Parameter #1 $value expects int, string given.',
    ): array {
        return [
            'path' => 'app/Example.php',
            'line' => $line,
            'identifier' => $identifier,
            'messageHash' => hash('sha256', $message),
            'sourceHash' => $sourceHash,
        ];
    }
}
