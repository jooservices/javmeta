<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

final class SettingsImportEnvCommand extends Command
{
    protected $signature = 'settings:import-env {--dry-run : Only list what would be written}';

    protected $description = 'Copy the current value (env or default) of every setting that is not in the database yet, so the env file is no longer needed for it.';

    public function handle(SettingsService $settings): int
    {
        $skipped = $settings->importSkippedPreview();
        if ((bool) $this->option('dry-run')) {
            $rows = $settings->importPreview();
            if ($rows === [] && $skipped === []) {
                $this->line('No missing settings would be imported.');

                return self::SUCCESS;
            }
            if ($rows !== []) {
                $this->table(['Setting', 'Current value', 'Source'], array_map(
                    static fn(array $row): array => [$row['key'], self::shorten(is_scalar($row['display']) ? (string) $row['display'] : json_encode($row['display'], JSON_THROW_ON_ERROR)), $row['source']],
                    $rows,
                ));
                $this->line(count($rows) . ' missing settings would be stored; secrets are redacted.');
            }
            $this->reportSkipped($skipped);

            return self::SUCCESS;
        }

        $written = $settings->importDefaults();
        $this->components->info(count($written) . ' settings stored in the database' . ($written === [] ? ' (all were already there).' : ': ' . implode(', ', $written)));
        $this->reportSkipped($skipped);

        return self::SUCCESS;
    }

    /** A very long default (for example a large allow-list) must not widen every table row, so cells are cut to a readable length. */
    private static function shorten(string $value): string
    {
        return mb_strlen($value) > 120 ? mb_substr($value, 0, 117) . '...' : $value;
    }

    /** @param list<array{key: string, environment: string, reason: string}> $skipped */
    private function reportSkipped(array $skipped): void
    {
        if ($skipped === []) {
            return;
        }

        $this->components->warn('Skipped deploy-specific environment paths:');
        $this->table(['Setting', 'Environment key', 'Reason'], array_map(
            static fn(array $row): array => [$row['key'], $row['environment'], $row['reason']],
            $skipped,
        ));
    }
}
