<?php

declare(strict_types=1);

/**
 * Merge one or more Clover reports and enforce a per-file statement floor.
 *
 * Usage:
 *   php tools/quality/coverage-per-file.php [min=85] build/coverage.xml [build/integration.xml ...]
 *
 * Paths are normalised from PHPUnit/Docker forms (`/app/app/...`, absolute worktree
 * paths) to repository-relative `app/...`. Only files with executable statement
 * lines are measured. Exit 0 when every measured app file is at or above the floor.
 */

$min = 85.0;
$clovers = [];

foreach (array_slice($argv, 1) as $arg) {
    if (is_numeric($arg) && ! str_contains((string) $arg, DIRECTORY_SEPARATOR) && ! str_ends_with((string) $arg, '.xml')) {
        $min = (float) $arg;
        continue;
    }
    $clovers[] = $arg;
}

if ($clovers === []) {
    $clovers = ['build/coverage.xml', 'build/integration.xml'];
}

$root = getcwd() ?: '.';
$lines = [];

foreach ($clovers as $clover) {
    if (! is_file($clover)) {
        fwrite(STDERR, "Coverage file {$clover} not found.\n");
        exit(1);
    }

    $xml = simplexml_load_file($clover);
    if ($xml === false) {
        fwrite(STDERR, "Coverage file {$clover} is not valid XML.\n");
        exit(1);
    }

    foreach ($xml->xpath('//file') ?: [] as $file) {
        $name = normalisePath((string) $file['name'], $root);
        if ($name === null || ! str_starts_with($name, 'app/')) {
            continue;
        }

        foreach ($file->line as $line) {
            if ((string) $line['type'] !== 'stmt') {
                continue;
            }
            $num = (int) $line['num'];
            $count = (int) $line['count'];
            $lines[$name][$num] = max($lines[$name][$num] ?? 0, $count);
        }
    }
}

$rows = [];
$totalStatements = 0;
$totalCovered = 0;

foreach ($lines as $path => $statementCounts) {
    $statements = count($statementCounts);
    if ($statements === 0) {
        continue;
    }
    $covered = count(array_filter($statementCounts, static fn(int $count): bool => $count > 0));
    $percentage = ($covered / $statements) * 100.0;
    $rows[] = [$path, $statements, $covered, $percentage];
    $totalStatements += $statements;
    $totalCovered += $covered;
}

usort($rows, static fn(array $left, array $right): int => $left[0] <=> $right[0]);
$below = array_values(array_filter($rows, static fn(array $row): bool => $row[3] < $min));
usort($below, static fn(array $left, array $right): int => ($right[1] - $right[2]) <=> ($left[1] - $left[2]));

$overall = $totalStatements > 0 ? ($totalCovered / $totalStatements) * 100.0 : 100.0;
printf(
    "Per-file coverage: %.2f%% overall (%d/%d statements across %d measurable app files); floor %.2f%%\n",
    $overall,
    $totalCovered,
    $totalStatements,
    count($rows),
    $min,
);
printf("Files below floor: %d\n", count($below));

foreach ($below as [$path, $statements, $covered, $percentage]) {
    printf(
        "  %6.2f%%  (%d/%d; need %d more)  %s\n",
        $percentage,
        $covered,
        $statements,
        (int) ceil(($min / 100.0) * $statements) - $covered,
        $path,
    );
}

exit($below === [] ? 0 : 1);

function normalisePath(string $name, string $root): ?string
{
    $name = str_replace('\\', '/', $name);
    $root = rtrim(str_replace('\\', '/', $root), '/');

    if (str_starts_with($name, '/app/')) {
        return ltrim(substr($name, strlen('/app/')), '/');
    }

    if ($root !== '' && str_starts_with($name, $root . '/')) {
        return substr($name, strlen($root) + 1);
    }

    if (str_starts_with($name, 'app/')) {
        return $name;
    }

    $marker = '/app/';
    $pos = strpos($name, $marker);
    if ($pos !== false) {
        return ltrim(substr($name, $pos + strlen($marker)), '/');
    }

    return null;
}
