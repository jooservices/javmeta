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
            $saved = array_keys($settings->all());
            $this->line('Dry run: ' . count($saved) . ' settings are defined; run without --dry-run to store the missing ones.');

            return self::SUCCESS;
        }

        $written = $settings->importDefaults();
        $this->components->info(count($written) . ' settings stored in the database' . ($written === [] ? ' (all were already there).' : ': ' . implode(', ', $written)));

        return self::SUCCESS;
    }
}
