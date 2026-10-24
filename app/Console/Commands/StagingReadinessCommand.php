<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Health\StagingReadiness;
use Illuminate\Console\Command;

final class StagingReadinessCommand extends Command
{
    protected $signature = 'staging:readiness {--json : Print machine-readable JSON} {--no-record : Do not store the run or alert}';

    protected $description = 'Is staging ready to run on its own? PASS or FAIL per check; exits non-zero on any FAIL.';

    public function handle(StagingReadiness $readiness): int
    {
        $report = $readiness->run(! (bool) $this->option('no-record'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($report['checks'] as $check) {
                $this->line('[' . strtoupper($check['status']) . "] {$check['name']}: {$check['message']}" . ($check['action'] !== null ? " -> {$check['action']}" : ''));
            }
            $this->line('Staging is ' . ($report['status'] === 'pass' ? 'READY' : 'NOT READY'));
        }

        return $report['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
