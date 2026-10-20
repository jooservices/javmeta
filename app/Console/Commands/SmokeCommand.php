<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Health\DeploymentSmokeCheck;
use Illuminate\Console\Command;

final class SmokeCommand extends Command
{
    protected $signature = 'javmeta:smoke {--json : Print machine-readable JSON results}';

    protected $description = 'Check that the deployed JavMeta services and APIs are healthy.';

    public function handle(DeploymentSmokeCheck $smokeCheck): int
    {
        $checks = $smokeCheck->run(
            app(\App\Services\Health\StatusHealthCheck::class),
            app(\App\Services\Dependencies\DependencyMonitor::class),
            app(\App\Services\Health\SchedulerHeartbeat::class),
            app(\Laravel\Horizon\Contracts\MasterSupervisorRepository::class),
            app(\App\Services\Auth\ApiKeyService::class),
            app(\Sentry\State\HubInterface::class),
        );
        $failed = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'fail')) > 0;

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => $failed ? 'fail' : 'pass',
                'checks' => $checks,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        foreach ($checks as $check) {
            $label = strtoupper($check['status']);
            $this->line("[{$label}] {$check['name']}: {$check['message']}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
