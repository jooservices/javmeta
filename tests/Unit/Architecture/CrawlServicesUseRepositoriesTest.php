<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CrawlServicesUseRepositoriesTest extends TestCase
{
    public function test_crawl_services_do_not_build_database_queries_directly(): void
    {
        $directory = dirname(__DIR__, 3) . '/app/Services/Crawl';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
        $violations = [];

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            self::assertIsString($source);

            if (preg_match('/\bDB::(?:table|select(?:One)?)\s*\(/', $source) === 1) {
                $violations[] = $file->getPathname() . ' calls the DB facade directly';
            }

            preg_match_all('/^use App\\\\Models\\\\([A-Za-z_][A-Za-z0-9_]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;/m', $source, $imports, PREG_SET_ORDER);
            foreach ($imports as $import) {
                $model = $import[2] ?? $import[1];
                if (preg_match('/\b' . preg_quote($model, '/') . '::(?:query|where|find|create|firstOrCreate|updateOrCreate)\s*\(/', $source) === 1) {
                    $violations[] = $file->getPathname() . ' queries an Eloquent model directly';
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
