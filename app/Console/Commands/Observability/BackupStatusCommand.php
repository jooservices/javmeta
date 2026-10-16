<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Observability\OpenObserveClient;
use App\Services\Alerts\AlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class BackupStatusCommand extends Command
{
    protected $signature = 'javmeta:backup-status';

    protected $description = 'Publish the last successful backup age and alert when it is stale.';

    public function handle(OpenObserveClient $client, AlertDispatcher $alerts): int
    {
        $age = $this->lastSuccessAge((string) config('backup.manifest_path'));
        $client->ingestMetrics([
            [
                'name' => 'javmeta_backup_last_success_age_seconds',
                'value' => $age ?? -1,
            ],
        ]);

        $threshold = max(1, (int) config('backup.stale_after_seconds', 26 * 60 * 60));
        if ($age === null) {
            $alerts->send(
                debounceKey: 'backup:missing',
                subject: 'Backup manifest missing',
                body: 'No successful database backup manifest is available.',
                context: ['backup_age_seconds' => -1],
            );
            $this->error('No successful backup manifest is available.');

            return self::FAILURE;
        }

        if ($age > $threshold) {
            $alerts->send(
                debounceKey: 'backup:stale',
                subject: 'Database backup is stale',
                body: 'The last successful database backup is older than the configured freshness limit.',
                context: ['backup_age_seconds' => $age, 'threshold_seconds' => $threshold],
            );
            $this->warn("Last successful backup is stale ({$age} seconds old).");

            return self::FAILURE;
        }

        $this->info("Last successful backup is current ({$age} seconds old).");

        return self::SUCCESS;
    }

    private function lastSuccessAge(string $manifestPath): ?int
    {
        if ($manifestPath === '' || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            $createdAt = $manifest['created_at'] ?? null;
            if (! is_string($createdAt) || $createdAt === '') {
                return null;
            }

            $timestamp = CarbonImmutable::parse($createdAt)->getTimestamp();

            return max(0, now()->getTimestamp() - $timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
