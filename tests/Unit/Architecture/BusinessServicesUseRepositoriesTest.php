<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class BusinessServicesUseRepositoriesTest extends TestCase
{
    public function test_business_services_jobs_and_commands_do_not_query_models_or_database_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $violations = [];

        foreach (['Services', 'Jobs', 'Console'] as $area) {
            $directory = $root . '/app/' . $area;
            if (! is_dir($directory)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
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

        self::assertSame([], array_values(array_unique($violations)), implode("\n", array_values(array_unique($violations))));
    }
}
