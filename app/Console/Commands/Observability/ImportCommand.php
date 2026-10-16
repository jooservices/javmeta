<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Services\Observability\ObservabilityImporter;
use Illuminate\Console\Command;
use Throwable;

final class ImportCommand extends Command
{
    protected $signature = 'observability:import {--dry-run : Show the import plan without contacting OpenObserve} {--strict : Fail when an alert could not be created because its stream does not exist yet}';

    protected $description = 'Import versioned dashboards and alert rules into OpenObserve.';

    public function handle(ObservabilityImporter $importer): int
    {
        try {
            $result = $importer->import((bool) $this->option('dry-run'));
        } catch (Throwable $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }

        if ($result['dry_run']) {
            $this->components->info(sprintf(
                'Dry run: would upsert %d dashboards, %d alerts, and one Telegram template and destination.',
                $result['dashboards'],
                $result['alerts'],
            ));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Imported %d dashboards and %d alerts (created %d dashboards, %d alerts; updated %d dashboards, %d alerts).',
            $result['dashboards'],
            $result['alerts'],
            $result['dashboards_created'],
            $result['alerts_created'],
            $result['dashboards_updated'],
            $result['alerts_updated'],
        ));

        $pending = $result['alerts_pending'];
        if ($pending !== []) {
            $this->components->warn('Alerts pending until their metric stream receives data (rerun after the first metrics publish): ' . implode(', ', $pending));

            if ((bool) $this->option('strict')) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
