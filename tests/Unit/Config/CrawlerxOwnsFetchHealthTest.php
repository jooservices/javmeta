<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * crawlerx owns soft-404 detection and fetch health; javmeta must not read or
 * write its old per-source circuit / soft-404 columns (kept in the schema
 * until the owner decides to drop them).
 */
final class CrawlerxOwnsFetchHealthTest extends TestCase
{
    private const FORBIDDEN = [
        'soft404_markers',
        'circuit_opened_at',
        'Soft404Detector',
        'SourceCircuitBreaker',
        'CIRCUIT_OPEN',
        '->circuit_state',
        "'circuit_state' =>",
        'source->consecutive_failures',
        'javmeta_source_consecutive_failures',
    ];

    public function test_app_and_config_do_not_use_source_circuit_or_soft404_fields(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['app', 'config'] as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory));
            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN as $needle) {
                    if ($needle === "'circuit_state' =>" && str_ends_with($file->getPathname(), 'CrawlStatusService.php')) {
                        continue; // deprecated constant key kept for MCP crawl_status stability
                    }

                    self::assertStringNotContainsString($needle, $contents, $file->getPathname());
                }
            }
        }
    }
}
