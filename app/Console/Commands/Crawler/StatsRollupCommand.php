<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
use App\Observability\MetricStreams;
use App\Observability\OpenObserveClient;
use App\Services\Crawl\CrawlStatsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Throwable;

final class StatsRollupCommand extends Command
{
    protected $signature = 'crawler:stats-rollup {--since= : Recompute hourly stats starting at this date or time}';

    protected $description = 'Roll up recent crawl attempts into durable hourly statistics.';

    public function handle(CrawlStatsRollup $rollup, OpenObserveClient $client): int
    {
        $this->pruneExpired();

        if (! (bool) config('javmeta_stats.enabled', true)) {
            $this->components->info('Crawl statistics are disabled.');

            return self::SUCCESS;
        }

        $until = now('UTC')->toImmutable();
        try {
            $from = $this->option('since') !== null
                ? CarbonImmutable::parse((string) $this->option('since'), 'UTC')
                : $until->startOfHour()->subHours(2);
        } catch (Throwable) {
            $this->components->error('The --since value must be a valid date or timestamp.');

            return self::FAILURE;
        }

        try {
            $result = $rollup->rollup($from, $until, $this->option('since') !== null);
        } catch (InvalidArgumentException) {
            $retentionDays = max(1, (int) config('javmeta_stats.retention_days', 365));
            $this->components->error(sprintf('The --since value must be within the last %d days.', $retentionDays));

            return self::FAILURE;
        }

        $this->exportMetrics($client, $until);
        $this->components->info(sprintf(
            'Rolled up %d hours and %d source/lane/method rows in %.2f ms.',
            $result['hours'],
            $result['attempt_rows'],
            $result['elapsed_ms'],
        ));

        return self::SUCCESS;
    }

    private function pruneExpired(): void
    {
        if (! Schema::hasTable('crawl_stats_hourly')) {
            return;
        }

        $retentionDays = max(1, (int) config('javmeta_stats.retention_days', 365));
        app(CrawlQueueRepository::class)->tableQuery('crawl_stats_hourly')
            ->where('hour_at', '<', now('UTC')->subDays($retentionDays))
            ->delete();
    }

    private function exportMetrics(OpenObserveClient $client, CarbonImmutable $until): void
    {
        if (! Schema::hasTable('crawl_stats_hourly')) {
            return;
        }

        $from = $until->startOfHour()->subHours(2);
        $rows = app(CrawlQueueRepository::class)->tableQuery('crawl_stats_hourly')
            ->where('hour_at', '>=', $from)
            ->where('hour_at', '<=', $until)
            ->orderBy('hour_at')
            ->get();
        $points = [];
        $bySourceHour = [];

        foreach ($rows as $row) {
            $hour = CarbonImmutable::parse((string) $row->hour_at)->utc()->toIso8601String();
            if ($row->source_slug === CrawlStatsRollup::GLOBAL_SOURCE
                && $row->lane === CrawlStatsRollup::GLOBAL_LANE
                && $row->method === CrawlStatsRollup::GLOBAL_METHOD) {
                $points[] = [
                    'name' => MetricStreams::NAMES['stats_movies_per_hour'],
                    'value' => (int) $row->movies_new + (int) $row->movies_updated,
                    'labels' => ['hour' => $hour],
                ];

                continue;
            }

            $key = $hour . '|' . $row->source_slug;
            $bySourceHour[$key] ??= ['hour' => $hour, 'source' => (string) $row->source_slug, 'ok' => 0, 'attempts' => 0, 'skipped' => 0];
            $bySourceHour[$key]['ok'] += (int) $row->ok;
            $bySourceHour[$key]['attempts'] += (int) $row->attempts;
            $bySourceHour[$key]['skipped'] += (int) $row->skipped;
        }

        foreach ($bySourceHour as $sourceHour) {
            $attemptCount = $sourceHour['attempts'] - $sourceHour['skipped'];
            $points[] = [
                'name' => MetricStreams::NAMES['stats_source_success_ratio'],
                'value' => $attemptCount > 0 ? $sourceHour['ok'] / $attemptCount : 0.0,
                'labels' => ['hour' => $sourceHour['hour'], 'source' => $sourceHour['source']],
            ];
        }

        try {
            $client->ingestMetrics($points);
        } catch (Throwable $exception) {
            Log::warning('Could not export hourly crawl statistics metrics.', ['exception' => $exception::class]);
        }
    }
}
