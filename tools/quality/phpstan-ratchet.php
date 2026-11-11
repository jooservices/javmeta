<?php

declare(strict_types=1);

use JavMeta\Quality\PhpstanOccurrenceRatchet;

require __DIR__ . '/PhpstanOccurrenceRatchet.php';

$projectRoot = dirname(__DIR__, 2);
$manifestPath = $projectRoot . '/phpstan-baseline-occurrences.json';
$updateBaseline = ($argv[1] ?? null) === '--update-baseline';
if (isset($argv[1]) && ! $updateBaseline) {
    fwrite(STDERR, "Usage: php tools/quality/phpstan-ratchet.php [--update-baseline]\n");
    exit(2);
}

try {
    if ($updateBaseline) {
        runPhpstan([
            'analyse',
            '--configuration=phpstan-ratchet.neon',
            '--generate-baseline',
            'phpstan-baseline.neon',
            '--no-progress',
            '--memory-limit=512M',
        ], $projectRoot, allowFindings: false);
    }

    [, $analysisOutput] = runPhpstan([
        'analyse',
        '--configuration=phpstan-ratchet.neon',
        '--error-format=json',
        '--no-progress',
        '--memory-limit=512M',
    ], $projectRoot, allowFindings: true);

    try {
        $report = json_decode($analysisOutput, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('PHPStan did not return valid JSON output.', previous: $exception);
    }
    if (! is_array($report)) {
        throw new RuntimeException('PHPStan returned an invalid JSON result.');
    }

    $occurrences = PhpstanOccurrenceRatchet::occurrences($report, $projectRoot);
    if ($updateBaseline) {
        $manifest = PhpstanOccurrenceRatchet::manifest($occurrences);
        if (file_put_contents($manifestPath, $manifest, LOCK_EX) === false) {
            throw new RuntimeException('Could not write phpstan-baseline-occurrences.json.');
        }
        printf("Updated the PHPStan occurrence baseline with %d findings. Review both baseline files.\n", count($occurrences));
        exit(0);
    }

    if (! is_file($manifestPath)) {
        throw new RuntimeException('PHPStan occurrence baseline is missing; generate it with composer lint:phpstan:baseline.');
    }
    try {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('PHPStan occurrence baseline is not valid JSON.', previous: $exception);
    }
    if (! is_array($manifest) || ($manifest['schemaVersion'] ?? null) !== 1 || ! is_array($manifest['occurrences'] ?? null)) {
        throw new RuntimeException('PHPStan occurrence baseline has an unsupported schema.');
    }

    $comparison = PhpstanOccurrenceRatchet::compare($occurrences, $manifest['occurrences']);
    if ($comparison['unexpected'] !== [] || $comparison['stale'] !== []) {
        foreach (['unexpected' => 'New', 'stale' => 'Stale'] as $key => $label) {
            foreach ($comparison[$key] as $occurrence) {
                printf(
                    "%s PHPStan occurrence (%s:%d, %s)%s\n",
                    $label,
                    $occurrence['path'],
                    $occurrence['line'],
                    $occurrence['identifier'],
                    $occurrence['count'] > 1 ? " (count {$occurrence['count']})" : '',
                );
            }
        }
        exit(1);
    }

    printf("PHPStan level 6 passed: %d existing occurrences match the reviewed location baseline.\n", count($occurrences));
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}

/**
 * @param list<string> $arguments
 * @return array{int, string}
 */
function runPhpstan(array $arguments, string $projectRoot, bool $allowFindings): array
{
    $command = [PHP_BINARY, $projectRoot . '/vendor/bin/phpstan', ...$arguments];
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $environment = getenv();
    if (! is_array($environment)) {
        $environment = [];
    }
    $environment['XDEBUG_MODE'] = 'off';

    $process = proc_open($command, $descriptors, $pipes, $projectRoot, $environment);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start PHPStan.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status > 1 || (! $allowFindings && $status !== 0)) {
        throw new RuntimeException("PHPStan exited with status {$status}.");
    }
    if ($status === 1 && ! $allowFindings) {
        throw new RuntimeException('PHPStan baseline generation reported errors.');
    }

    return [$status, $output];
}
