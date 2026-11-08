<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SettingsHealthReportUseRepositoriesTest extends TestCase
{
    public function test_settings_health_and_report_services_do_not_query_models_or_database_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            $root . '/app/Services/Settings',
            $root . '/app/Services/Health',
            $root . '/app/Services/Report',
            $root . '/app/Services/Reports',
        ];
        $violations = [];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
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
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
