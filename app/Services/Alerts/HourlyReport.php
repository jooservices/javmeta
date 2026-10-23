<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Contracts\ReportChannel;
use App\Services\Crawl\CrawlStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * One short message per hour: how many movies and performers exist now and how many were added since the
 * previous report. The previous values live in a private state file that is rewritten only after a successful send.
 */
final class HourlyReport
{
    public function __construct(
        private readonly CrawlStatusService $status,
        private readonly ReportChannel $channel,
    ) {}

    /**
     * @return array{sent: bool, message: string, counts: array{movies: int, performers: int}}
     */
    public function run(bool $dryRun = false): array
    {
        $now = CarbonImmutable::now();
        $counts = $this->status->counts();
        $previous = $this->previous();
        $message = $this->message($counts, $previous, $now);

        if ($dryRun) {
            return ['sent' => false, 'message' => $message, 'counts' => $counts];
        }

        $sent = $this->channel->deliver('Hourly report', $message);
        if ($sent) {
            $this->store($counts, $now);
        }

        return ['sent' => $sent, 'message' => $message, 'counts' => $counts];
    }

    /**
     * @param  array{movies: int, performers: int}  $counts
     * @param  array{movies: int, performers: int, at: CarbonImmutable}|null  $previous
     */
    public function message(array $counts, ?array $previous, CarbonImmutable $now): string
    {
        $timezone = (string) config('javmeta_alerts.hourly_report.timezone', 'Asia/Ho_Chi_Minh');
        $lines = ['Report ' . $now->setTimezone($timezone)->format('Y-m-d H:i') . ' (' . $timezone . ')'];

        if ($previous === null) {
            $lines[] = 'Movies: ' . number_format($counts['movies']);
            $lines[] = 'Performers: ' . number_format($counts['performers']);
            $lines[] = 'First report: there is no earlier value to compare with.';

            return implode("\n", $lines);
        }

        $minutes = max(1, (int) round($previous['at']->diffInMinutes($now, true)));
        $period = intdiv($minutes, 60) > 0 ? intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm' : $minutes . 'm';
        $lines[] = 'Movies: ' . number_format($counts['movies']) . ' (' . $this->change($counts['movies'] - $previous['movies']) . ' in ' . $period . ')';
        $lines[] = 'Performers: ' . number_format($counts['performers']) . ' (' . $this->change($counts['performers'] - $previous['performers']) . ')';

        return implode("\n", $lines);
    }

    private function change(int $difference): string
    {
        return ($difference >= 0 ? '+' : '-') . number_format(abs($difference));
    }

    public function statePath(): string
    {
        $configured = (string) config('javmeta_alerts.hourly_report.state_path', '');

        return $configured !== '' ? $configured : storage_path('app/private/monitoring/hourly-report-state.json');
    }

    /**
     * @return array{movies: int, performers: int, at: CarbonImmutable}|null
     */
    private function previous(): ?array
    {
        $path = $this->statePath();
        if (! File::exists($path)) {
            return null;
        }

        $data = json_decode((string) File::get($path), true);
        if (! is_array($data) || ! isset($data['movies'], $data['performers'], $data['last_notified_at'])) {
            return null;
        }

        return [
            'movies' => (int) $data['movies'],
            'performers' => (int) $data['performers'],
            'at' => CarbonImmutable::parse((string) $data['last_notified_at']),
        ];
    }

    /**
     * @param  array{movies: int, performers: int}  $counts
     */
    private function store(array $counts, CarbonImmutable $now): void
    {
        $path = $this->statePath();
        File::ensureDirectoryExists(dirname($path), 0700);
        $temporary = $path . '.tmp';
        File::put($temporary, (string) json_encode([
            'movies' => $counts['movies'],
            'performers' => $counts['performers'],
            'last_notified_at' => $now->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES) . "\n");
        chmod($temporary, 0600);
        rename($temporary, $path);
    }
}
