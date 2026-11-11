<?php

declare(strict_types=1);

namespace JavMeta\Quality;

use JsonException;
use RuntimeException;

final class PhpstanOccurrenceRatchet
{
    /**
     * @param array<string, mixed> $report
     * @return list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}>
     */
    public static function occurrences(array $report, string $projectRoot): array
    {
        $root = realpath($projectRoot);
        if ($root === false) {
            throw new RuntimeException('The project root does not exist.');
        }

        if (! isset($report['files']) || ! is_array($report['files'])) {
            throw new RuntimeException('PHPStan JSON did not contain a files object.');
        }

        $occurrences = [];
        $sourceLinesByPath = [];

        foreach ($report['files'] as $reportedPath => $result) {
            if (! is_string($reportedPath) || ! is_array($result) || ! isset($result['messages']) || ! is_array($result['messages'])) {
                throw new RuntimeException('PHPStan JSON contained an invalid file result.');
            }

            $absolutePath = realpath($reportedPath);
            if ($absolutePath === false || ! str_starts_with($absolutePath, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('PHPStan reported a file outside the project root.');
            }

            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($root) + 1));
            $sourceLinesByPath[$relativePath] ??= file($absolutePath, FILE_IGNORE_NEW_LINES);
            $sourceLines = $sourceLinesByPath[$relativePath];
            if (! is_array($sourceLines)) {
                throw new RuntimeException("Could not read PHPStan source file {$relativePath}.");
            }

            foreach ($result['messages'] as $message) {
                if (! is_array($message) || ! is_int($message['line'] ?? null) || $message['line'] < 1 || ! is_string($message['message'] ?? null) || ! is_string($message['identifier'] ?? null)) {
                    throw new RuntimeException('PHPStan JSON contained an invalid diagnostic.');
                }

                $sourceLine = $sourceLines[$message['line'] - 1] ?? null;
                if (! is_string($sourceLine)) {
                    throw new RuntimeException("PHPStan line {$message['line']} is outside {$relativePath}.");
                }

                $occurrences[] = [
                    'path' => $relativePath,
                    'line' => $message['line'],
                    'identifier' => $message['identifier'],
                    'messageHash' => hash('sha256', $message['message']),
                    'sourceHash' => hash('sha256', $sourceLine),
                ];
            }
        }

        usort($occurrences, self::compareOccurrences(...));

        return $occurrences;
    }

    /**
     * @param list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}> $actual
     * @param list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}> $expected
     * @return array{
     *     unexpected: list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string, count: int}>,
     *     stale: list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string, count: int}>
     * }
     */
    public static function compare(array $actual, array $expected): array
    {
        $actualCounts = self::countOccurrences($actual);
        $expectedCounts = self::countOccurrences($expected);
        $unexpected = [];
        $stale = [];

        foreach ($actualCounts as $key => $entry) {
            $difference = $entry['count'] - ($expectedCounts[$key]['count'] ?? 0);
            if ($difference > 0) {
                $unexpected[] = [...$entry['occurrence'], 'count' => $difference];
            }
        }

        foreach ($expectedCounts as $key => $entry) {
            $difference = $entry['count'] - ($actualCounts[$key]['count'] ?? 0);
            if ($difference > 0) {
                $stale[] = [...$entry['occurrence'], 'count' => $difference];
            }
        }

        usort($unexpected, self::compareOccurrences(...));
        usort($stale, self::compareOccurrences(...));

        return ['unexpected' => $unexpected, 'stale' => $stale];
    }

    /** @param list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}> $occurrences */
    public static function manifest(array $occurrences): string
    {
        try {
            return json_encode(
                [
                    'schemaVersion' => 1,
                    'identity' => ['path', 'line', 'identifier', 'messageHash', 'sourceHash'],
                    'occurrences' => $occurrences,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Could not encode the PHPStan occurrence manifest.', previous: $exception);
        }
    }

    /**
     * @param list<array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}> $occurrences
     * @return array<string, array{occurrence: array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string}, count: int}>
     */
    private static function countOccurrences(array $occurrences): array
    {
        $counts = [];

        foreach ($occurrences as $occurrence) {
            $key = implode("\0", [
                $occurrence['path'],
                (string) $occurrence['line'],
                $occurrence['identifier'],
                $occurrence['messageHash'],
                $occurrence['sourceHash'],
            ]);

            if (! isset($counts[$key])) {
                $counts[$key] = ['occurrence' => $occurrence, 'count' => 0];
            }
            $counts[$key]['count']++;
        }

        return $counts;
    }

    /** @param array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string, count?: int} $left
     *  @param array{path: string, line: int, identifier: string, messageHash: string, sourceHash: string, count?: int} $right
     */
    private static function compareOccurrences(array $left, array $right): int
    {
        return [$left['path'], $left['line'], $left['identifier'], $left['messageHash'], $left['sourceHash']]
            <=> [$right['path'], $right['line'], $right['identifier'], $right['messageHash'], $right['sourceHash']];
    }
}
