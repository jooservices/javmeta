<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

final class SettingsEnvCleanupCommand extends Command
{
    protected $signature = 'settings:env-cleanup {--dry-run : List redundant environment keys without editing any file}';

    protected $description = 'List legacy environment keys whose settings are already stored in the database; this command is always read-only.';

    public function handle(SettingsService $settings): int
    {
        $rows = $settings->redundantEnvironmentKeys();
        if ($rows === []) {
            $this->line('No registered legacy environment keys have a database setting.');

            return self::SUCCESS;
        }

        $this->table(['Setting', 'Redundant environment key'], array_map(
            static fn(array $row): array => [$row['setting'], $row['environment']],
            $rows,
        ));
        $this->line(count($rows) . ' environment keys are eligible for manual removal after verification.');

        return self::SUCCESS;
    }
}
