<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Models\CrawlEvent;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use Illuminate\Redis\Connections\Connection;
use Laravel\Horizon\Contracts\HorizonCommandQueue;
use Laravel\Horizon\Contracts\SupervisorRepository;
use stdClass;

final class HorizonWorkerReconciler
{
    private const LOCK_SECONDS = 60;

    public function __construct(
        private readonly HorizonWorkerLayout $layout,
        private readonly SettingsService $settings,
        private readonly HorizonCommandQueue $commands,
        private readonly SupervisorRepository $supervisors,
    ) {}

    /** @return array{status: string, old?: array<string, mixed>, new?: array<string, mixed>} */
    public function reconcile(): array
    {
        if (! $this->settings->apply()) {
            return ['status' => 'settings-unavailable'];
        }
        if ($this->settings->invalidWorkerSettings() !== []) {
            return ['status' => 'invalid-settings'];
        }
        $desired = $this->layout->snapshot();
        $node = (string) $desired['node'];
        $redis = Redis::connection(config('horizon.use', 'default'));
        $running = json_decode((string) $redis->get(HorizonLayoutSnapshot::key($node)), true);
        $old = is_array($running['snapshot'] ?? null) ? $running['snapshot'] : null;
        if ($old === null) {
            return ['status' => 'no-running-master'];
        }
        if (hash_equals((string) $desired['hash'], (string) ($old['hash'] ?? ''))) {
            $redis->del($this->mismatchKey($node));

            return ['status' => 'current', 'old' => $old, 'new' => $desired];
        }
        if ($this->deploymentLocked()) {
            return ['status' => 'deploy-locked', 'old' => $old, 'new' => $desired];
        }
        $scaleOnly = $this->workerCountsOnlyChanged($old, $desired);
        $master = $old['master'] ?? null;
        if (! $scaleOnly) {
            Log::warning('Horizon worker layout change requires a manual Horizon restart.', [
                'node' => $node,
                'old_hash' => $old['hash'] ?? null,
                'new_hash' => $desired['hash'],
            ]);

            return ['status' => 'restart-required', 'old' => $old, 'new' => $desired];
        }
        if (! is_string($master) || ! $this->activeSupervisorsAvailable($master, $desired)) {
            return ['status' => 'supervisors-unavailable', 'old' => $old, 'new' => $desired];
        }

        $token = bin2hex(random_bytes(16));
        $locked = $redis->command('set', [$this->lockKey($node), $token, ['NX', 'EX' => self::LOCK_SECONDS]]);
        if ($locked !== true && strtoupper((string) $locked) !== 'OK') {
            return ['status' => 'locked', 'old' => $old, 'new' => $desired];
        }

        $commandMayBeQueued = false;
        try {
            if ($this->deploymentLocked()) {
                return ['status' => 'deploy-locked', 'old' => $old, 'new' => $desired];
            }

            return $this->applyAfterJitter($redis, $node, $old, $desired, (string) $master, $commandMayBeQueued);
        } finally {
            // Release only this invocation's token; expiry remains the recovery path after a crash.
            $redis->command('eval', [
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [$this->lockKey($node), $token],
                1,
            ]);
        }
    }

    /** @param array<string, mixed> $old
     *  @param array<string, mixed> $desired
     *  @return array{status: string, old: array<string, mixed>, new: array<string, mixed>}
     */
    private function applyAfterJitter(
        Connection $redis,
        string $node,
        array $old,
        array $desired,
        string $master,
        bool &$commandMayBeQueued,
    ): array {
        $cooldownKey = $this->cooldownKey($node);
        if ($redis->exists($cooldownKey)) {
            return ['status' => 'cooldown', 'old' => $old, 'new' => $desired];
        }

        $mismatchKey = $this->mismatchKey($node);
        $started = (int) ($redis->get($mismatchKey) ?: 0);
        if ($started === 0) {
            $started = now()->timestamp;
            $redis->setex($mismatchKey, 900, (string) $started);
        }
        $jitter = max(0, (int) config('javmeta_workers.reconcile_jitter_seconds', 300));
        $delay = $jitter === 0 ? 0 : hexdec(substr(hash('sha256', $node), 0, 8)) % ($jitter + 1);
        if (now()->timestamp < $started + $delay) {
            return ['status' => 'jitter', 'old' => $old, 'new' => $desired];
        }

        $drainSeconds = $this->scaleDownSeconds($old, $desired);
        $fleetValue = json_encode([
            'node' => $node,
            'hash' => $desired['hash'],
            'release_after' => now()->timestamp + $drainSeconds,
        ], JSON_THROW_ON_ERROR);
        $fleetLockTtl = max(600, $drainSeconds + 60);
        $fleetLock = $redis->command('set', [HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK, $fleetValue, ['NX', 'EX' => $fleetLockTtl]]);
        if ($fleetLock !== true && strtoupper((string) $fleetLock) !== 'OK') {
            return ['status' => 'fleet-locked', 'old' => $old, 'new' => $desired];
        }

        try {
            CrawlEvent::query()->create([
                'source_slug' => 'worker-reconcile',
                'kind' => CrawlEvent::KIND_WORKERS_RECONFIGURED,
                'url' => null,
                'detail' => ['node' => $node, 'old' => $old, 'new' => $desired],
                'created_at' => now(),
            ]);
            $this->scaleWorkers($master, $old, $desired, $commandMayBeQueued);
            Log::info('Horizon worker counts reconciled.', [
                'node' => $node,
                'workers' => HorizonWorkerLayout::workerCounts($desired['supervisors']),
            ]);
            $cooldown = max(600, (int) config('javmeta_workers.reconcile_cooldown_seconds', 600));
            $redis->setex($cooldownKey, $cooldown, (string) now()->timestamp);
            $redis->del($mismatchKey);
        } catch (\Throwable $exception) {
            if (! $commandMayBeQueued) {
                $redis->command('eval', [
                    "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                    [HorizonLayoutSnapshot::FLEET_RECONCILE_LOCK, $fleetValue],
                    1,
                ]);
            }
            throw $exception;
        }

        return ['status' => 'scaled', 'old' => $old, 'new' => $desired];
    }

    /** @param array<string, mixed> $old
     *  @param array<string, mixed> $desired
     */
    private function workerCountsOnlyChanged(array $old, array $desired): bool
    {
        if (($old['node'] ?? null) !== ($desired['node'] ?? null)
            || ($old['role'] ?? null) !== ($desired['role'] ?? null)
            || array_keys((array) ($old['supervisors'] ?? [])) !== array_keys((array) ($desired['supervisors'] ?? []))) {
            return false;
        }

        $changed = false;
        foreach ($desired['supervisors'] as $name => $supervisor) {
            $previous = $old['supervisors'][$name] ?? null;
            if (! is_array($previous) || ! is_array($supervisor)) {
                return false;
            }
            $oldCount = $previous['maxProcesses'] ?? null;
            $newCount = $supervisor['maxProcesses'] ?? null;
            unset($previous['maxProcesses'], $supervisor['maxProcesses']);
            if ($previous !== $supervisor) {
                return false;
            }
            $changed = $changed || $oldCount !== $newCount;
        }

        return $changed;
    }

    /** @param array<string, mixed> $desired */
    private function activeSupervisorsAvailable(string $master, array $desired): bool
    {
        foreach (array_keys($desired['supervisors']) as $name) {
            /** @var stdClass|null $active */
            $active = $this->supervisors->find($master . ':' . $name);
            if ($active === null || ($active->master ?? null) !== $master || ($active->status ?? null) !== 'running') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $old
     *  @param array<string, mixed> $desired
     */
    private function scaleWorkers(string $master, array $old, array $desired, bool &$commandMayBeQueued): void
    {
        foreach ($desired['supervisors'] as $name => $supervisor) {
            $previous = $old['supervisors'][$name] ?? [];
            if (($previous['maxProcesses'] ?? null) === ($supervisor['maxProcesses'] ?? null)) {
                continue;
            }
            $commandMayBeQueued = true;
            $this->commands->push($master . ':' . $name, ReconcileHorizonScale::class, [
                'scale' => max(1, (int) $supervisor['maxProcesses']),
            ]);
        }
    }

    /** @param array<string, mixed> $old
     *  @param array<string, mixed> $desired
     */
    private function scaleDownSeconds(array $old, array $desired): int
    {
        foreach ($desired['supervisors'] as $name => $supervisor) {
            $oldCount = (int) ($old['supervisors'][$name]['maxProcesses'] ?? 1);
            $newCount = (int) ($supervisor['maxProcesses'] ?? 1);
            if ($newCount < $oldCount) {
                return max(0, (int) config('javmeta_queues.stop_grace_seconds', 200));
            }
        }

        return 0;
    }

    /** @phpstan-impure
     * The deploy lock may appear between the two guard checks.
     */
    private function deploymentLocked(): bool
    {
        $release = realpath(base_path()) ?: base_path();
        $root = dirname(dirname($release));
        $paths = [$root . '/shared/deploy.lock', base_path('shared/deploy.lock')];
        foreach (array_unique($paths) as $path) {
            if (! is_file($path)) {
                continue;
            }
            $handle = fopen($path, 'r');
            if ($handle === false) {
                return true;
            }
            $locked = ! flock($handle, LOCK_EX | LOCK_NB);
            if (! $locked) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            if ($locked) {
                return true;
            }
        }

        return false;
    }

    private function lockKey(string $node): string
    {
        return 'javmeta:horizon:reconcile:lock:' . $node;
    }

    private function cooldownKey(string $node): string
    {
        return 'javmeta:horizon:reconcile:cooldown:' . $node;
    }

    private function mismatchKey(string $node): string
    {
        return 'javmeta:horizon:reconcile:mismatch:' . $node;
    }
}
