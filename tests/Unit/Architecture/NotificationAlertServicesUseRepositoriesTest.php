<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NotificationAlertServicesUseRepositoriesTest extends TestCase
{
    public function test_notification_and_alert_services_do_not_build_database_queries_directly(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            $root . '/app/Services/Notifications',
            $root . '/app/Services/Alerts',
            $root . '/app/Console/Commands/Notifications',
            $root . '/app/Console/Commands/AlertsListCommand.php',
            $root . '/app/Console/Commands/AlertsEvaluateCommand.php',
            $root . '/app/Console/Commands/AlertsMetricsCommand.php',
            $root . '/app/Console/Commands/AlertsTestCommand.php',
            $root . '/app/Console/Commands/FollowNotifyCommand.php',
        ];
        $violations = [];

        foreach ($paths as $path) {
            $files = is_dir($path)
                ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path))
                : [$path];

            foreach ($files as $file) {
                if (is_object($file) && (! $file->isFile() || $file->getExtension() !== 'php')) {
                    continue;
                }
                $filePath = is_object($file) ? $file->getPathname() : $file;
                if (! is_file($filePath)) {
                    continue;
                }
                $source = file_get_contents($filePath);
                self::assertIsString($source);

                if (preg_match('/\bDB::(?:table|select(?:One)?)\s*\(/', $source) === 1) {
                    $violations[] = $filePath . ' calls the DB facade directly';
                }

                preg_match_all('/^use App\\\\Models\\\\([A-Za-z_][A-Za-z0-9_]*)(?:\s+as\s+([A-Za-z_][A-Za-z0-9_]*))?;/m', $source, $imports, PREG_SET_ORDER);
                foreach ($imports as $import) {
                    $model = $import[2] ?? $import[1];
                    if (preg_match('/\b' . preg_quote($model, '/') . '::(?:query|where|find|create|firstOrCreate|updateOrCreate)\s*\(/', $source) === 1) {
                        $violations[] = $filePath . ' queries an Eloquent model directly';
                    }
                }
            }
        }

        self::assertSame([], $violations, implode("\n", $violations));
    }
}
