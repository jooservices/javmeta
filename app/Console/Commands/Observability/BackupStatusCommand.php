<?php

declare(strict_types=1);

namespace App\Console\Commands\Observability;

use App\Observability\OpenObserveClient;
use App\Observability\MetricStreams;
use App\Services\Alerts\AlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class BackupStatusCommand extends Command
{
    protected $signature = 'javmeta:backup-status {--lenient : Exit 0 even when the backup is missing or stale (the metric and the alert are still sent); used by the scheduler}';

    protected $description = 'Publish the last successful backup age and alert when it is stale.';

    public function handle(OpenObserveClient $client, AlertDispatcher $alerts): int
    {
        $age = $this->lastSuccessAge((string) config('backup.manifest_path'));
        $client->ingestMetrics([
            [
                'name' => MetricStreams::NAMES['backup_last_success_age_seconds'],
                'value' => $age ?? -1,
            ],
        ]);

        $alerting = (bool) config('javmeta_backup.manifest_alert_enabled', true);
        $threshold = max(1, (int) config('backup.stale_after_seconds', 26 * 60 * 60));
        $throttle = (int) config('javmeta_alerts.throttle_seconds', 3600);
        if ($age === null) {
            if ($alerting) {
                $alerts->sendTemplate(
                    debounceKey: 'backup:missing',
                    templateKey: 'backup.manifest_missing',
                    context: ['backup_age_seconds' => -1],
                    debounceSeconds: $throttle,
                );
                $this->error('No successful backup manifest is available.');

                return $this->option('lenient') ? self::SUCCESS : self::FAILURE;
            }

            $this->info('No successful backup manifest is available; the manifest alert is disabled.');

            return self::SUCCESS;
        }

        if ($age > $threshold) {
            if ($alerting) {
                $alerts->sendTemplate(
                    debounceKey: 'backup:stale',
                    templateKey: 'backup.stale',
                    context: ['backup_age_seconds' => $age, 'threshold_seconds' => $threshold],
                    debounceSeconds: $throttle,
                );
                $this->warn("Last successful backup is stale ({$age} seconds old).");

                return $this->option('lenient') ? self::SUCCESS : self::FAILURE;
            }

            $this->warn("Last successful backup is stale ({$age} seconds old); the manifest alert is disabled.");

            return self::SUCCESS;
        }

        if ($alerting) {
            $alerts->resolveTemplate('backup:stale', 'backup.recovered', ['backup_age_seconds' => $age]);
            $alerts->resolveTemplate('backup:missing', 'backup.recovered', ['backup_age_seconds' => $age]);
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
