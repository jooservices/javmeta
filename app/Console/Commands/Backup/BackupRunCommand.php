<?php

declare(strict_types=1);

namespace App\Console\Commands\Backup;

use App\Observability\MetricStreams;
use App\Observability\OpenObserveClient;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Backup\LogicalBackupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Run the scheduled logical backup (PostgreSQL and MongoDB dumps) on the main node, honouring the enabled and interval
 * settings, publishing run/size metrics, and alerting when a dump fails. The manifest is only refreshed on success.
 */
final class BackupRunCommand extends Command
{
    protected $signature = 'javmeta:backup-run {--force : Run now even when the logical backup interval is not due}';

    protected $description = 'Run a scheduled logical PostgreSQL and MongoDB backup and refresh the manifest.';

    public function handle(LogicalBackupService $backup, OpenObserveClient $client, AlertDispatcher $alerts): int
    {
        $enabled = (bool) config('javmeta_backup.logical_enabled', false);

        if (! $enabled) {
            $this->info('Logical backup is disabled; skipping.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->isDue()) {
            $this->info('Logical backup is not due yet; skipping.');

            return self::SUCCESS;
        }

        try {
            $result = $backup->run();
        } catch (Throwable) {
            $result = [
                'completed' => false,
                'created_at' => null,
                'manifest_path' => '',
                'stores' => [],
                'failed_store' => 'unknown',
                'failed_exit_code' => -1,
            ];
        }
        $this->publishMetrics($client, $result);

        if (! $result['completed']) {
            $this->alertFailure($alerts, $result);
            $this->error('Database backup failed.');

            return self::FAILURE;
        }

        $this->info(sprintf('Logical backup completed (%s).', (string) $result['created_at']));

        return self::SUCCESS;
    }

    /** @param array{completed: bool, stores: array<string, array{bytes: int}>, failed_store: ?string} $result */
    private function publishMetrics(OpenObserveClient $client, array $result): void
    {
        $resultLabel = $result['completed'] ? 'success' : 'failure';
        $points = [[
            'name' => MetricStreams::NAMES['backup_runs_total'],
            'value' => (int) Cache::increment('javmeta:backup:runs:' . $resultLabel),
            'labels' => ['result' => $resultLabel],
        ]];

        if ($result['completed']) {
            foreach ($result['stores'] as $store => $meta) {
                $points[] = [
                    'name' => MetricStreams::NAMES['backup_last_size_bytes'],
                    'value' => (int) $meta['bytes'],
                    'labels' => ['store' => $store],
                ];
            }
        }

        $client->ingestMetrics($points);
    }

    /** @param array{failed_store: ?string} $result */
    private function alertFailure(AlertDispatcher $alerts, array $result): void
    {
        $alerts->send(
            debounceKey: 'backup:failed',
            subject: 'Database backup failed',
            body: 'The scheduled logical database backup failed; the previous manifest was left unchanged.',
            context: ['store' => (string) ($result['failed_store'] ?? 'unknown')],
            debounceSeconds: max(0, (int) config('javmeta_alerts.throttle_seconds', 3600)),
        );
    }

    private function isDue(): bool
    {
        $intervalHours = max(1, (int) config('javmeta_backup.logical_interval_hours', 24));
        $age = $this->lastSuccessAge((string) config('backup.manifest_path'));

        return $age === null || $age >= $intervalHours * 3600;
    }

    private function lastSuccessAge(string $manifestPath): ?int
    {
        if ($manifestPath === '' || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['kind'] ?? null) !== 'logical') {
                return null;
            }

            $createdAt = $manifest['created_at'] ?? null;
            if (! is_string($createdAt) || $createdAt === '') {
                return null;
            }

            return max(0, now()->getTimestamp() - CarbonImmutable::parse($createdAt)->getTimestamp());
        } catch (Throwable) {
            return null;
        }
    }
}
