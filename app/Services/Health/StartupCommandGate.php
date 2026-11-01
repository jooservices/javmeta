<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Observability\ObservabilityEmitter;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final readonly class StartupCommandGate
{
    public function __construct(
        private StartupHealthService $health,
        private ObservabilityEmitter $observability,
    ) {}

    public function handle(string $command): void
    {
        $command = strtolower(trim($command));
        if ($command === '' || ! $this->mustCheck($command)) {
            return;
        }

        $role = $this->roleFor($command);
        $report = $this->health->run($role, 0, false);
        if (! $report['blocking']) {
            return;
        }

        $failed = array_values(array_filter($report['checks'], static fn(array $row): bool => $row['class'] === 'primary' && $row['status'] === 'fail'));
        $names = array_map(static fn(array $row): string => $row['service'], $failed);
        try {
            $this->observability->emitOps('startup_blocked', [
                'role' => $role,
                'command' => $command,
                'failed_dependencies' => $names,
            ]);
        } catch (Throwable) {
            // A blocked command must not depend on the telemetry service.
        }

        throw new RuntimeException('Startup health blocked `' . $command . '` for role `' . $role . '`; unavailable primary dependencies: ' . implode(', ', $names) . '. Run `php artisan health:startup --role=' . $role . ' --wait=0` for details.');
    }

    private function mustCheck(string $command): bool
    {
        $denylist = (array) config('javmeta_health.startup.command_denylist', []);
        foreach ($denylist as $pattern) {
            if (is_string($pattern) && Str::is(strtolower($pattern), $command)) {
                return true;
            }
        }

        foreach ((array) config('javmeta_health.startup.command_allowlist', []) as $pattern) {
            if (is_string($pattern) && Str::is(strtolower($pattern), $command)) {
                return false;
            }
        }

        return true;
    }

    private function roleFor(string $command): string
    {
        return match (true) {
            str_starts_with($command, 'schedule:') => 'scheduler',
            $command === 'horizon', str_starts_with($command, 'queue:'), str_starts_with($command, 'crawler:'),
            str_starts_with($command, 'performers:'), str_starts_with($command, 'performer:'),
            str_starts_with($command, 'media:'), str_starts_with($command, 'search:backfill'),
            str_starts_with($command, 'library:'), str_starts_with($command, 'ai:warm'),
            str_starts_with($command, 'telegram:poll'), str_starts_with($command, 'follows:notify') => 'worker',
            default => 'web',
        };
    }
}
