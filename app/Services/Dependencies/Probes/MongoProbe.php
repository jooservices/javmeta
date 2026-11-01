<?php

declare(strict_types=1);

namespace App\Services\Dependencies\Probes;

use App\Services\Dependencies\DetailedDependencyProbe;
use App\Services\Dependencies\ProbeResult;
use Closure;
use JOOservices\LaravelConfig\Contracts\ConfigStore;
use RuntimeException;
use MongoDB\Driver\Command;
use MongoDB\Driver\Manager;
use Throwable;

final class MongoProbe implements DetailedDependencyProbe
{
    /** @var Closure(): void */
    private readonly Closure $ping;

    /** @var Closure(): void */
    private readonly Closure $readSettings;

    /**
     * @param  Closure(): void|null  $ping  Optional ping seam for tests.
     * @param  Closure(): void|null  $readSettings  Optional settings-store seam for tests.
     */
    public function __construct(?Closure $ping = null, ?Closure $readSettings = null)
    {
        $this->ping = $ping ?? static function (): void {
            $dsn = (string) config('database.connections.mongodb.dsn', '');
            if ($dsn === '') {
                throw new RuntimeException('MongoDB DSN is not configured.');
            }

            $timeout = max(1, (int) config('javmeta_health.startup.timeout_seconds', 2));
            $manager = new Manager($dsn, [
                'serverSelectionTimeoutMS' => $timeout * 1000,
                'socketTimeoutMS' => $timeout * 1000,
            ]);
            $manager->executeCommand('admin', new Command(['ping' => 1]));
        };
        $this->readSettings = $readSettings ?? static function (): void {
            app(ConfigStore::class)->group('settings');
        };
    }

    public function probe(): bool
    {
        return $this->check()->available === true;
    }

    public function check(): ProbeResult
    {
        try {
            ($this->ping)();
        } catch (Throwable) {
            return new ProbeResult(false, 'MongoDB ping failed.');
        }

        try {
            ($this->readSettings)();
        } catch (Throwable) {
            return new ProbeResult(false, 'MongoDB settings-store read failed.');
        }

        return new ProbeResult(true, 'MongoDB ping and settings-store read succeeded.');
    }
}
