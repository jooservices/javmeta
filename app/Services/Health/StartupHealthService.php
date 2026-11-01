<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Observability\ObservabilityEmitter;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Settings\SettingsService;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final class StartupHealthService
{
    private const BOOTSTRAP_PRIMARY = ['postgres', 'redis', 'mongo'];

    private readonly Closure $sleep;

    private readonly Closure $clock;

    public function __construct(
        private readonly DependencyMonitor $dependencies,
        private readonly SettingsService $settings,
        private readonly ObservabilityEmitter $observability,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
        $this->clock = $clock ?? static fn(): float => hrtime(true) / 1_000_000_000;
    }

    /** @return array<string, mixed> */
    public function run(string $role, ?int $waitSeconds = null, bool $includeSecondaries = true): array
    {
        $this->assertRole($role);
        $wait = max(0, $waitSeconds ?? (int) config('javmeta_health.startup.wait_seconds', 60));
        $deadline = ($this->clock)() + $wait;
        $primary = [];

        do {
            $primary = $this->checkPrimary($role);
            if ($primary['primary_passed'] || $wait === 0 || ($this->clock)() >= $deadline) {
                break;
            }

            $backoff = max(1, (int) config('javmeta_health.startup.retry_backoff_seconds', 1));
            ($this->sleep)(min((float) $backoff, max(0.0, $deadline - ($this->clock)())));
        } while (true);

        $checks = $primary['checks'];
        if ($includeSecondaries) {
            $checks = [...$checks, ...$this->checkSecondaries($role, $primary['primary'])];
        }

        return [
            'role' => $role,
            'enabled' => $primary['enabled'],
            'primary' => $primary['primary'],
            'primary_passed' => $primary['primary_passed'],
            'blocking' => $primary['enabled'] && ! $primary['primary_passed'],
            'settings_loaded' => $primary['settings_loaded'],
            'checked_at' => now()->utc()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /** @return array{enabled: bool, primary: list<string>, primary_passed: bool, settings_loaded: bool, checks: list<array<string, mixed>>} */
    private function checkPrimary(string $role): array
    {
        $checks = [];
        foreach (self::BOOTSTRAP_PRIMARY as $service) {
            $checks[] = $this->check($service, 'primary');
        }

        $bootstrapPassed = ! array_filter($checks, static fn(array $check): bool => $check['status'] === 'fail');
        $settingsStarted = hrtime(true);
        $timeout = max(1, (int) config('javmeta_health.startup.timeout_seconds', 2));
        config([
            'database.connections.mongodb.options.serverSelectionTimeoutMS' => $timeout * 1000,
            'database.connections.mongodb.options.socketTimeoutMS' => $timeout * 1000,
        ]);
        DB::purge('mongodb');
        $settingsLoaded = $bootstrapPassed && $this->settings->applyFresh();
        $settingsLatency = (int) round((hrtime(true) - $settingsStarted) / 1_000_000);
        foreach ($checks as &$check) {
            if ($check['service'] !== Dependency::Mongo->value || $check['status'] === 'fail') {
                continue;
            }
            $check['latency_ms'] += $settingsLatency;
            $check['status'] = $settingsLoaded ? 'pass' : 'fail';
            $check['message'] = $settingsLoaded
                ? 'MongoDB ping and settings-store read succeeded.'
                : 'MongoDB settings-store read was unavailable or deferred.';
        }
        unset($check);

        $enabled = $settingsLoaded ? (bool) config('javmeta_health.startup.enabled', true) : true;
        $primary = self::BOOTSTRAP_PRIMARY;

        if ($settingsLoaded) {
            $configured = config("javmeta_health.startup.primary.{$role}", []);
            if (is_array($configured)) {
                $primary = array_values(array_unique([
                    ...$primary,
                    ...array_filter($configured, static fn(mixed $service): bool => is_string($service) && in_array($service, ['elasticsearch'], true)),
                ]));
            }
        }

        foreach (array_diff($primary, self::BOOTSTRAP_PRIMARY) as $service) {
            $checks[] = $this->check($service, 'primary');
        }

        $passed = ! array_filter($checks, static fn(array $check): bool => $check['status'] === 'fail');

        return [
            'enabled' => $enabled,
            'primary' => $primary,
            'primary_passed' => $passed,
            'settings_loaded' => $settingsLoaded,
            'checks' => $checks,
        ];
    }

    /** @param list<string> $primary @return list<array<string, mixed>> */
    private function checkSecondaries(string $role, array $primary): array
    {
        $checks = [];
        foreach (Dependency::cases() as $dependency) {
            if (in_array($dependency->value, $primary, true)) {
                continue;
            }

            $row = $this->check($dependency->value, 'secondary');
            $checks[] = $row;
            if ($row['status'] === 'warn') {
                try {
                    Log::warning('Startup secondary dependency is unavailable.', [
                        'dependency' => $dependency->value,
                        'role' => $role,
                        'latency_ms' => $row['latency_ms'],
                    ]);
                    $this->observability->emitDependency($dependency->value, 'startup_check', false, $row['latency_ms']);
                } catch (Throwable) {
                    // Secondary telemetry must not turn a warning into a startup failure.
                }
            }
        }

        return $checks;
    }

    /** @return array{service: string, class: string, status: string, latency_ms: int, message: string} */
    private function check(string $service, string $class): array
    {
        $started = hrtime(true);
        $result = $this->dependencies->probeNow(Dependency::from($service));
        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $status = match (true) {
            $result->available === null => 'disabled',
            $result->available => 'pass',
            $class === 'secondary' => 'warn',
            default => 'fail',
        };

        return [
            'service' => $service,
            'class' => $class,
            'status' => $status,
            'latency_ms' => $latency,
            'message' => $result->message,
        ];
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, ['web', 'worker', 'scheduler'], true)) {
            throw new InvalidArgumentException('Role must be web, worker or scheduler.');
        }
    }
}
