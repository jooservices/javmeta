<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * crawlerx owns soft-404 detection; javmeta must not maintain its own
 * soft-404 markers or detector.
 */
final class CrawlerxOwnsFetchHealthTest extends TestCase
{
    private const FORBIDDEN = [
        'soft404_markers',
        'Soft404Detector',
    ];

    public function test_app_and_config_do_not_implement_soft404_detection(): void
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
