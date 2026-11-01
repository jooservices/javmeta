<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Models\CrawlEvent;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Redis\Connections\Connection;

final class HorizonWorkerReconciler
{
    private const LOCK_SECONDS = 60;

    public function __construct(private readonly HorizonWorkerLayout $layout, private readonly SettingsService $settings) {}

    /** @return array{status: string, old?: array<string, mixed>, new?: array<string, mixed>} */
    public function reconcile(callable $terminate): array
    {
        $this->settings->apply();
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

        $token = bin2hex(random_bytes(16));
        $locked = $redis->command('set', [$this->lockKey($node), $token, ['NX', 'EX' => self::LOCK_SECONDS]]);
        if ($locked !== true && strtoupper((string) $locked) !== 'OK') {
            return ['status' => 'locked', 'old' => $old, 'new' => $desired];
        }

        try {
            if ($this->deploymentLocked()) {
                return ['status' => 'deploy-locked', 'old' => $old, 'new' => $desired];
            }

            return $this->terminateAfterJitter($redis, $node, $old, $desired, $terminate);
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
    private function terminateAfterJitter(Connection $redis, string $node, array $old, array $desired, callable $terminate): array
    {
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

        $fleetValue = json_encode(['node' => $node, 'hash' => $desired['hash']], JSON_THROW_ON_ERROR);
        $fleetLock = $redis->command('set', [HorizonLayoutSnapshot::FLEET_RESTART_LOCK, $fleetValue, ['NX', 'EX' => 600]]);
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
            $terminate();
            $cooldown = max(600, (int) config('javmeta_workers.reconcile_cooldown_seconds', 600));
            $redis->setex($cooldownKey, $cooldown, (string) now()->timestamp);
            $redis->del($mismatchKey);
        } catch (\Throwable $exception) {
            $redis->command('eval', [
                "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end",
                [HorizonLayoutSnapshot::FLEET_RESTART_LOCK, $fleetValue],
                1,
            ]);
            throw $exception;
        }

        return ['status' => 'terminated', 'old' => $old, 'new' => $desired];
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
