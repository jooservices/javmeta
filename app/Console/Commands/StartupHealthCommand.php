<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Health\StartupHealthService;
use Illuminate\Console\Command;

final class StartupHealthCommand extends Command
{
    protected $signature = 'health:startup {--role= : web, worker or scheduler} {--wait= : Seconds to wait for primary dependencies}';

    protected $description = 'Check startup dependencies and report primary and secondary health.';

    public function handle(StartupHealthService $health): int
    {
        $role = (string) ($this->option('role') ?: 'web');
        $wait = $this->option('wait');
        if ($wait !== null && (! ctype_digit((string) $wait) || (int) $wait > 600)) {
            $this->error('--wait must be a whole number between 0 and 600.');

            return self::FAILURE;
        }

        try {
            $report = $health->run($role, $wait === null ? null : (int) $wait);
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Service', 'Class', 'Status', 'Latency (ms)', 'Message'],
            array_map(static fn(array $row): array => [
                $row['service'], $row['class'], $row['status'], $row['latency_ms'], $row['message'],
            ], $report['checks']),
        );

        if ($report['blocking']) {
            $failed = array_values(array_filter($report['checks'], static fn(array $row): bool => $row['class'] === 'primary' && $row['status'] === 'fail'));
            $this->error('Startup blocked: ' . implode('; ', array_map(static fn(array $row): string => $row['service'] . ' — ' . $row['message'], $failed)));

            return self::FAILURE;
        }

        if (! $report['enabled'] && ! $report['primary_passed']) {
            $this->warn('Startup health gate is disabled; primary failures are reported but do not block.');
        }

        return self::SUCCESS;
    }
}
