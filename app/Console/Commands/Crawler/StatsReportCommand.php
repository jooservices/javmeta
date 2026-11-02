<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Services\Crawl\CrawlStatsRollup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use LogicException;

final class StatsReportCommand extends Command
{
    protected $signature = 'crawler:stats-report {--hours=24} {--source=} {--by=source}';

    protected $description = 'Report durable crawl statistics by source, method, lane, or hour.';

    public function handle(): int
    {
        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 8760],
        ]);
        if ($hours === false) {
            $this->components->error('The --hours value must be between 1 and 8760.');

            return self::FAILURE;
        }

        $by = (string) $this->option('by');
        if (! in_array($by, ['source', 'method', 'lane', 'hour'], true)) {
            $this->components->error('The --by value must be source, method, lane, or hour.');

            return self::FAILURE;
        }

        $until = now('UTC')->toImmutable();
        $from = $until->startOfHour()->subHours($hours - 1);
        $groups = $this->groupsFor($from, $until, $by, trim((string) $this->option('source')));
        $this->displayGroups($groups);
        $this->displayTrend($from, $until);

        return self::SUCCESS;
    }

    /** @return array<string, array<string, int|float|bool|null>> */
    private function groupsFor(CarbonImmutable $from, CarbonImmutable $until, string $by, string $source): array
    {
        $query = DB::table('crawl_stats_hourly')
            ->where('hour_at', '>=', $from)
            ->where('hour_at', '<=', $until)
            ->where('source_slug', '<>', CrawlStatsRollup::GLOBAL_SOURCE);
        if ($source !== '') {
            $query->where('source_slug', $source);
        }

        $groups = [];
        foreach ($query->get() as $row) {
            $hour = CarbonImmutable::parse((string) $row->hour_at)->utc();
            $group = match ($by) {
                'source' => (string) $row->source_slug,
                'method' => (string) $row->method,
                'lane' => (string) $row->lane,
                'hour' => $hour->format('Y-m-d H:00'),
                default => throw new LogicException('Unsupported crawl statistics grouping.'),
            };
            $groups[$group] ??= [
                'attempts' => 0,
                'ok' => 0,
                'blocked' => 0,
                'timeout' => 0,
                'other_error' => 0,
                'skipped' => 0,
                'duration_sum' => 0.0,
                'p90_max' => null,
                'bytes' => 0,
                'has_bytes' => false,
            ];

            $groups[$group]['attempts'] += (int) $row->attempts;
            $groups[$group]['ok'] += (int) $row->ok;
            $groups[$group]['blocked'] += (int) $row->blocked;
            $groups[$group]['timeout'] += (int) $row->timeout;
            $groups[$group]['other_error'] += (int) $row->other_error;
            $groups[$group]['skipped'] += (int) $row->skipped;
            $groups[$group]['duration_sum'] += (float) ($row->avg_duration_ms ?? 0) * (int) $row->attempts;
            if ($row->p90_duration_ms !== null) {
                $groups[$group]['p90_max'] = max((int) ($groups[$group]['p90_max'] ?? 0), (int) $row->p90_duration_ms);
            }
            if ($row->bytes_total !== null) {
                $groups[$group]['bytes'] += (int) $row->bytes_total;
                $groups[$group]['has_bytes'] = true;
            }
        }

        ksort($groups);

        return $groups;
    }

    /** @param array<string, array<string, int|float|bool|null>> $groups */
    private function displayGroups(array $groups): void
    {
        if ($groups === []) {
            $this->components->info('No crawl attempt statistics are available in this time range.');

            return;
        }

        $rows = [];
        foreach ($groups as $label => $values) {
            $attempts = (int) $values['attempts'];
            $skipped = (int) $values['skipped'];
            $measured = $attempts - $skipped;
            $rows[] = [
                $label,
                $attempts,
                (int) $values['ok'],
                $measured > 0 ? number_format((int) $values['ok'] / $measured * 100, 1) . '%' : '—',
                (int) $values['blocked'],
                (int) $values['timeout'],
                (int) $values['other_error'],
                $skipped,
                $attempts > 0 ? number_format((float) $values['duration_sum'] / $attempts, 1) : '—',
                $values['p90_max'] ?? '—',
                $values['has_bytes'] ? $values['bytes'] : '—',
            ];
        }

        $this->table(
            ['Group', 'Attempts', 'OK', 'Success', 'Blocked', 'Timeout', 'Other error', 'Skipped', 'Avg ms', 'Max slice p90 ms', 'Bytes'],
            $rows,
        );
    }

    private function displayTrend(CarbonImmutable $from, CarbonImmutable $until): void
    {
        $trend = DB::table('crawl_stats_hourly')
            ->where('source_slug', CrawlStatsRollup::GLOBAL_SOURCE)
            ->where('hour_at', '>=', $from)
            ->where('hour_at', '<=', $until)
            ->orderBy('hour_at')
            ->get();
        $trendRows = [];
        foreach ($trend as $row) {
            $trendRows[] = [
                CarbonImmutable::parse((string) $row->hour_at)->utc()->format('Y-m-d H:00'),
                (int) $row->movies_new,
                (int) $row->movies_updated,
                (int) $row->performers_new,
                (int) $row->jobs_failed,
                $row->queue_ready_avg === null ? '—' : number_format((float) $row->queue_ready_avg, 1),
                $row->queue_delayed_avg === null ? '—' : number_format((float) $row->queue_delayed_avg, 1),
                $row->queue_reserved_avg === null ? '—' : number_format((float) $row->queue_reserved_avg, 1),
                $row->playwright_busy_ratio_avg === null ? '—' : number_format((float) $row->playwright_busy_ratio_avg, 3),
                $row->flaresolverr_busy_ratio_avg === null ? '—' : number_format((float) $row->flaresolverr_busy_ratio_avg, 3),
                $row->postgres_connections_avg === null ? '—' : number_format((float) $row->postgres_connections_avg, 1),
            ];
        }

        $this->newLine();
        $this->components->info('Movies per hour and global operational averages.');
        if ($trendRows === []) {
            $this->components->info('No hourly global statistics are available in this time range.');
        } else {
            $this->table(
                ['Hour (UTC)', 'New movies', 'Updated', 'New performers', 'Failed jobs', 'Ready avg', 'Delayed avg', 'Reserved avg', 'Playwright busy', 'FlareSolverr busy', 'PG connections avg'],
                $trendRows,
            );
        }
    }
}
