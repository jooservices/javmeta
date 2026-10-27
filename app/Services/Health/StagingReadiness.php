<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\ReportChannel;
use App\Services\Auth\ApiKeyService;
use App\Services\Crawl\CrawlStatusService;
use App\Services\Dependencies\DependencyMonitor;
use Illuminate\Support\Facades\File;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Sentry\State\HubInterface;

/**
 * One answer to "is staging ready to run on its own": the deployment smoke check plus checks that the crawl is moving,
 * nothing is stuck, enough sources work and the last backup is fresh. A FAIL names its cause and the first thing to do.
 */
final class StagingReadiness
{
    private const HISTORY_LIMIT = 30;

    public function __construct(
        private readonly DeploymentSmokeCheck $smoke,
        private readonly CrawlStatusService $crawlStatus,
        private readonly ReportChannel $channel,
        private readonly SourceScheduleFreshness $sourceFreshness,
    ) {}

    /**
     * Run deployment and crawler readiness checks and optionally persist state transitions.
     *
     * @return array{status: string, checked_at: string, checks: list<array{name: string, status: string, message: string, action: ?string}>}
     */
    public function run(bool $record = true): array
    {
        $checks = [];
        $smoke = $this->smoke->run(
            app(StatusHealthCheck::class),
            app(DependencyMonitor::class),
            app(SchedulerHeartbeat::class),
            app(MasterSupervisorRepository::class),
            app(ApiKeyService::class),
            app(HubInterface::class),
        );
        foreach ($smoke as $check) {
            $checks[] = $this->row($check['name'], $check['status'], $check['message'], $check['status'] === 'fail' ? 'Run `php artisan javmeta:smoke` and read the failing line; see docs/03-operations/staging-runbook.md.' : null);
        }

        $status = $this->crawlStatus->status();
        array_push($checks, ...$this->crawlChecks($status));
        $checks[] = $this->backupCheck();
        $checks[] = $this->alertCheck();

        $failed = count(array_filter($checks, static fn(array $c): bool => $c['status'] === 'fail')) > 0;
        $report = ['status' => $failed ? 'fail' : 'pass', 'checked_at' => now()->utc()->toIso8601String(), 'checks' => $checks];

        if ($record) {
            $this->record($report);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return list<array{name: string, status: string, message: string, action: ?string}>
     */
    private function crawlChecks(array $status): array
    {
        $rows = [];
        $maxAge = (int) config('javmeta_readiness.max_newest_movie_age_minutes', 30);
        $newest = \App\Models\Movie::query()->max('first_seen_at');
        $ageMinutes = $newest === null ? null : (int) floor((time() - strtotime((string) $newest)) / 60);
        $rows[] = $ageMinutes !== null && $ageMinutes <= $maxAge
            ? $this->row('newest_movie', 'pass', "The newest movie was added {$ageMinutes} minutes ago.")
            : $this->row('newest_movie', 'fail', $ageMinutes === null ? 'There are no movies.' : "The newest movie is {$ageMinutes} minutes old (limit {$maxAge}).", 'Check Horizon and the scheduler, then `php artisan crawler:sources`.');

        $stuck = (int) ($status['queue']['stuck_claimed'] ?? 0);
        $rows[] = $stuck === 0
            ? $this->row('stuck_jobs', 'pass', 'No job is stuck in claimed.')
            : $this->row('stuck_jobs', 'fail', "{$stuck} jobs are stuck in claimed.", 'Run `php artisan crawler:reclaim`; if it repeats, restart javmeta-horizon.');

        $enabled = array_values(array_filter((array) ($status['sources'] ?? []), static fn(array $s): bool => (bool) $s['enabled']));
        $defaultStaleSeconds = (int) config('javmeta_alerts.watchdog.source_stale_seconds', 86400);
        $dead = [];
        $notYetDue = [];
        foreach ($enabled as $source) {
            $slug = (string) $source['slug'];
            $lastSuccessAt = is_string($source['last_success_at'] ?? null) ? $source['last_success_at'] : null;
            $lastSuccessTimestamp = $lastSuccessAt === null ? false : strtotime($lastSuccessAt);
            if ($lastSuccessTimestamp === false || $this->sourceFreshness->isPastDue($lastSuccessAt, $slug, $defaultStaleSeconds)) {
                $dead[] = $slug;
            } elseif ($lastSuccessTimestamp < time() - $defaultStaleSeconds) {
                $notYetDue[] = $slug;
            }
        }

        $maxDead = (int) config('javmeta_readiness.max_dead_sources', 3);
        $list = $dead === [] ? 'none' : implode(', ', $dead);
        $scheduleNote = $notYetDue === [] ? '' : ' Not yet due under configured cycle: ' . implode(', ', $notYetDue) . '.';
        $summary = count($enabled) - count($dead) . '/' . count($enabled) . " enabled sources are current or not yet due; past due: {$list}." . $scheduleNote;
        $rows[] = count($dead) <= $maxDead
            ? $this->row('sources', $dead === [] ? 'pass' : 'warn', $summary)
            : $this->row('sources', 'fail', count($dead) . " enabled sources are past due (limit {$maxDead}): {$list}." . $scheduleNote, 'Run `php artisan crawler:failures` and open a CrawlerX ticket per source.');

        return $rows;
    }

    /** @return array{name: string, status: string, message: string, action: ?string} */
    private function backupCheck(): array
    {
        $file = storage_path('app/private/backups/latest.json');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $at = is_array($data) ? ($data['created_at'] ?? $data['finished_at'] ?? $data['generated_at'] ?? null) : null;
        $ageHours = is_string($at) && strtotime($at) !== false ? (int) floor((time() - strtotime($at)) / 3600) : null;
        $max = (int) config('javmeta_readiness.max_backup_age_hours', 26);

        return $ageHours !== null && $ageHours <= $max
            ? $this->row('backup', 'pass', "The last backup is {$ageHours} hours old.")
            : $this->row('backup', 'fail', $ageHours === null ? 'There is no readable backup manifest.' : "The last backup is {$ageHours} hours old (limit {$max}).", 'Run `deploy/backup/staging-backup.sh` and check `systemctl --user status javmeta-backup.timer`.');
    }

    /** @return array{name: string, status: string, message: string, action: ?string} */
    private function alertCheck(): array
    {
        $token = (string) (config('laravel-notifications.channels.telegram.bot_token') ?: config('javmeta_alerts.telegram.bot_token'));
        $chat = (string) (config('laravel-notifications.channels.telegram.chat_id') ?: config('javmeta_alerts.telegram.chat_id'));

        return $token !== '' && $chat !== ''
            ? $this->row('alert_channel', 'pass', 'The Telegram alert channel is configured.')
            : $this->row('alert_channel', 'fail', 'The Telegram bot token or chat id is missing.', 'Set the Telegram values in .env (keep its ACL) and restart the units.');
    }

    /** @return array{name: string, status: string, message: string, action: ?string} */
    private function row(string $name, string $status, string $message, ?string $action = null): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message, 'action' => $status === 'fail' ? $action : null];
    }

    /** @param array{status: string, checked_at: string, checks: list<array<string, mixed>>} $report */
    private function record(array $report): void
    {
        $file = storage_path('app/private/monitoring/readiness-history.jsonl');
        File::ensureDirectoryExists(dirname($file));
        $lines = is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)))) : [];
        $previous = $lines === [] ? null : json_decode((string) end($lines), true);
        $failing = array_values(array_map(static fn(array $c): string => (string) $c['name'], array_filter($report['checks'], static fn(array $c): bool => $c['status'] === 'fail')));

        $lines[] = (string) json_encode(['checked_at' => $report['checked_at'], 'status' => $report['status'], 'failing' => $failing], JSON_UNESCAPED_SLASHES);
        File::put($file, implode("\n", array_slice($lines, -self::HISTORY_LIMIT)) . "\n");

        // Alert once when the state turns bad or its failing checks change, then explicitly announce recovery.
        if ($report['status'] === 'fail' && (! is_array($previous) || ($previous['failing'] ?? []) !== $failing)) {
            $first = array_values(array_filter($report['checks'], static fn(array $c): bool => $c['status'] === 'fail'))[0] ?? null;
            $this->channel->deliver(
                'Staging readiness',
                'Staging is NOT ready. Failing: ' . implode(', ', $failing) . '. ' . ($first['message'] ?? '') . ' ' . ($first['action'] ?? ''),
                ['alert_state' => 'open', 'severity' => 'warning'],
            );
        } elseif ($report['status'] === 'pass' && is_array($previous) && ($previous['status'] ?? null) === 'fail') {
            $resolved = (array) ($previous['failing'] ?? []);
            $this->channel->deliver(
                'Staging readiness recovered',
                'Staging is READY. Recovered checks: ' . ($resolved === [] ? 'previous failure' : implode(', ', $resolved)) . '.',
                ['alert_state' => 'resolved', 'severity' => 'info', 'resolved_checks' => count($resolved), 'checked_at' => $report['checked_at']],
            );
        }
    }
}
