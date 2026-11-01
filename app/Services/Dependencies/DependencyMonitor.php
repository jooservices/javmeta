<?php

declare(strict_types=1);

namespace App\Services\Dependencies;

use Illuminate\Support\Facades\Log;
use Throwable;

final class DependencyMonitor
{
    private const CACHE_SECONDS = 10;

    private const FAILURE_THRESHOLD = 3;

    private const CIRCUIT_COOLDOWN_SECONDS = 30;

    /**
     * @var array<string, array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }>
     */
    private array $states = [];

    /**
     * @param  array<string, DependencyProbe>  $probes  Keyed by dependency value.
     */
    public function __construct(private readonly array $probes) {}

    /**
     * @return array{dependency: string, hard: bool, state: string, available: bool, checked_at: string|null}
     */
    public function check(Dependency $dependency): array
    {
        $key = $dependency->value;
        $now = now()->getTimestamp();
        $state = $this->states[$key] ?? $this->initialState();
        $probe = $this->probes[$key] ?? null;

        if (! $probe instanceof DependencyProbe) {
            $state = $this->changeState($dependency, $state, 'disabled');
            $state['available'] = true;
            $state['cached_at'] = $now;
            $state['checked_at'] = now()->toIso8601String();

            return $this->storeAndFormat($dependency, $state);
        }

        if ($state['state'] === 'open' && $state['retry_at'] !== null) {
            if ($now < $state['retry_at']) {
                $this->states[$key] = $state;

                return $this->format($dependency, $state);
            }

            $state = $this->changeState($dependency, $state, 'half_open');
        } elseif (
            $state['cached_at'] !== null
            && ($now - $state['cached_at']) < self::CACHE_SECONDS
        ) {
            $this->states[$key] = $state;

            return $this->format($dependency, $state);
        }

        try {
            if ($probe instanceof DetailedDependencyProbe) {
                $result = $probe->check();
                if ($result->available === null) {
                    $state = $this->changeState($dependency, $state, 'disabled');
                    $state['available'] = true;
                    $state['failures'] = 0;
                    $state['cached_at'] = $now;
                    $state['retry_at'] = null;
                    $state['checked_at'] = now()->toIso8601String();

                    return $this->storeAndFormat($dependency, $state);
                }

                $available = $result->available;
            } else {
                $available = $probe->probe();
            }
        } catch (Throwable) {
            $available = false;
        }

        $state['available'] = $available;
        $state['cached_at'] = $now;
        $state['checked_at'] = now()->toIso8601String();

        if ($available) {
            $state = $this->changeState($dependency, $state, 'healthy');
            $state['failures'] = 0;
            $state['retry_at'] = null;
        } else {
            $state['failures']++;
            if ($state['failures'] >= self::FAILURE_THRESHOLD) {
                $state = $this->changeState($dependency, $state, 'open');
                $state['retry_at'] = $now + self::CIRCUIT_COOLDOWN_SECONDS;
            } else {
                $state = $this->changeState($dependency, $state, 'down');
            }
        }

        return $this->storeAndFormat($dependency, $state);
    }

    public function isAvailable(Dependency $dependency): bool
    {
        return $this->check($dependency)['available'];
    }

    public function probeNow(Dependency $dependency): ProbeResult
    {
        $probe = $this->probes[$dependency->value] ?? null;
        if (! $probe instanceof DependencyProbe) {
            return new ProbeResult(true, 'Check is not configured.');
        }

        try {
            if ($probe instanceof DetailedDependencyProbe) {
                return $probe->check();
            }

            $available = $probe->probe();

            return new ProbeResult($available, $available ? 'Connection succeeded.' : 'Connection failed.');
        } catch (Throwable) {
            return new ProbeResult(false, 'Connection failed.');
        }
    }

    /**
     * @return array<string, array{dependency: string, hard: bool, state: string, available: bool, checked_at: string|null}>
     */
    public function statuses(): array
    {
        $statuses = [];
        foreach (Dependency::cases() as $dependency) {
            $statuses[$dependency->value] = $this->check($dependency);
        }

        return $statuses;
    }

    public function state(Dependency $dependency): string
    {
        $state = $this->states[$dependency->value] ?? null;
        if ($state === null) {
            return 'unknown';
        }

        if (
            $state['state'] === 'open'
            && $state['retry_at'] !== null
            && now()->getTimestamp() >= $state['retry_at']
        ) {
            return 'half_open';
        }

        return $state['state'];
    }

    /**
     * @return array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }
     */
    private function initialState(): array
    {
        return [
            'state' => 'unknown',
            'failures' => 0,
            'cached_at' => null,
            'available' => true,
            'retry_at' => null,
            'checked_at' => null,
        ];
    }

    /**
     * @param  array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }  $state
     * @return array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }
     */
    private function changeState(Dependency $dependency, array $state, string $nextState): array
    {
        $previousState = $state['state'];
        if ($previousState === $nextState) {
            return $state;
        }

        $state['state'] = $nextState;
        if ($previousState === 'disabled' || $nextState === 'disabled') {
            return $state;
        }

        $context = [
            'dependency' => $dependency->value,
            'from' => $previousState,
            'to' => $nextState,
            'role' => (string) config('dependencies.role', 'api'),
        ];
        $level = in_array($nextState, ['down', 'open'], true) ? 'warning' : 'info';

        if (
            $dependency === Dependency::Observability
            && in_array($nextState, ['down', 'open', 'half_open'], true)
        ) {
            Log::channel('stderr')->log($level, 'Runtime dependency state changed.', $context);
        } else {
            Log::log($level, 'Runtime dependency state changed.', $context);
        }

        return $state;
    }

    /**
     * @param  array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }  $state
     * @return array{dependency: string, hard: bool, state: string, available: bool, checked_at: string|null}
     */
    private function storeAndFormat(Dependency $dependency, array $state): array
    {
        $this->states[$dependency->value] = $state;

        return $this->format($dependency, $state);
    }

    /**
     * @param  array{
     *     state: string,
     *     failures: int,
     *     cached_at: int|null,
     *     available: bool,
     *     retry_at: int|null,
     *     checked_at: string|null
     * }  $state
     * @return array{dependency: string, hard: bool, state: string, available: bool, checked_at: string|null}
     */
    private function format(Dependency $dependency, array $state): array
    {
        $role = (string) config('dependencies.role', 'api');
        $startupRole = in_array($role, ['api', 'mcp'], true) ? 'web' : $role;
        $primary = config("javmeta_health.startup.primary.{$startupRole}");
        if (is_array($primary)) {
            $primary = array_unique([...['postgres', 'redis', 'mongo'], ...$primary]);
            $level = in_array($dependency->value, $primary, true) ? 'hard' : 'soft';
        } else {
            $level = (string) config("dependencies.roles.{$role}.{$dependency->value}", 'soft');
        }

        return [
            'dependency' => $dependency->value,
            'hard' => $level === 'hard',
            'state' => $state['state'],
            'available' => $state['available'],
            'checked_at' => $state['checked_at'],
        ];
    }
}
