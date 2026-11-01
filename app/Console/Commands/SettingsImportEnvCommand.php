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
        if ((bool) $this->option('dry-run')) {
            $rows = $settings->importPreview();
            if ($rows === []) {
                $this->line('No missing settings would be imported.');

                return self::SUCCESS;
            }
            $this->table(['Setting', 'Current value', 'Source'], array_map(
                static fn(array $row): array => [$row['key'], is_scalar($row['display']) ? (string) $row['display'] : json_encode($row['display'], JSON_THROW_ON_ERROR), $row['source']],
                $rows,
            ));
            $this->line(count($rows) . ' missing settings would be stored; secrets are redacted.');

            return self::SUCCESS;
        }

        $written = $settings->importDefaults();
        $this->components->info(count($written) . ' settings stored in the database' . ($written === [] ? ' (all were already there).' : ': ' . implode(', ', $written)));

        return self::SUCCESS;
    }
}
