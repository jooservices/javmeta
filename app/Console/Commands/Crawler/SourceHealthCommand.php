<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Contracts\ReportChannel;
use App\Models\Source;
use App\Services\Crawler\CrawlerxClient;
use App\Services\Health\SourceHealthChecker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class SourceHealthCommand extends Command
{
    protected $signature = 'crawler:source-health {--source=} {--delay=5} {--json} {--no-write}';

    protected $description = 'Check every enabled source live (one public page per kind, polite delays) and report WORKING, DEGRADED or BLOCKED with the reason.';

    public function handle(CrawlerxClient $client, ReportChannel $channel): int
    {
        $delay = max(1, (int) $this->option('delay'));
        $checker = new SourceHealthChecker($client, static function (int $seconds): void {
            sleep($seconds);
        });
        $only = trim((string) $this->option('source'));
        $enabledInDatabase = Source::query()->where('enabled', true)->pluck('slug')->all();

        $rows = [];
        foreach ((array) config('javmeta_sources.sources', []) as $slug => $config) {
            $slug = (string) $slug;
            if ($only !== '' && $only !== $slug) {
                continue;
            }
            $enabled = in_array($slug, $enabledInDatabase, true);
            array_push($rows, ...$checker->check($slug, is_array($config) ? $config : [], $enabled, $delay));
        }

        $report = ['generated_at' => now()->utc()->toIso8601String(), 'summary' => $this->summary($rows), 'rows' => $rows];

        if (! $this->option('no-write')) {
            $this->write($report);
            $this->recordHistory($report, $channel);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Source', 'Page', 'Verdict', 'Error', 'Reason'], array_map(
            static fn(array $row): array => [$row['source'], $row['capability'], $row['verdict'], $row['error_code'] ?? '', mb_substr((string) $row['reason'], 0, 80)],
            $rows,
        ));
        $this->line(sprintf('WORKING %d, DEGRADED %d, BLOCKED %d, DISABLED %d', ...array_values($report['summary'])));

        return self::SUCCESS;
    }

    /**
     * @param  list<array{source: string, capability: string, url: string, verdict: string, error_code: ?string, reason: ?string}>  $rows
     * @return array{WORKING: int, DEGRADED: int, BLOCKED: int, DISABLED: int}
     */
    private function summary(array $rows): array
    {
        $summary = [SourceHealthChecker::WORKING => 0, SourceHealthChecker::DEGRADED => 0, SourceHealthChecker::BLOCKED => 0, SourceHealthChecker::DISABLED => 0];
        foreach ($rows as $row) {
            $summary[$row['verdict']]++;
        }

        return $summary;
    }

    /**
     * Append one line to the history and tell Telegram when a source is blocked now that was not at the previous check.
     * The first check is only the baseline, so it never alerts.
     *
     * @param array{generated_at: string, summary: array<string, int>, rows: list<array<string, mixed>>} $report
     */
    private function recordHistory(array $report, ReportChannel $channel): void
    {
        $file = base_path('artifacts/reports/source-health-history.jsonl');
        $blocked = array_values(array_unique(array_map(
            static fn(array $row): string => (string) $row['source'],
            array_filter($report['rows'], static fn(array $row): bool => $row['verdict'] === SourceHealthChecker::BLOCKED),
        )));
        sort($blocked);

        $previous = null;
        if (is_file($file)) {
            $lines = array_filter(explode("\n", (string) file_get_contents($file)));
            $last = $lines === [] ? null : json_decode((string) end($lines), true);
            $previous = is_array($last) ? $last : null;
        }

        File::append($file, (string) json_encode(['generated_at' => $report['generated_at'], 'summary' => $report['summary'], 'blocked' => $blocked], JSON_UNESCAPED_SLASHES) . "\n");

        if ($previous === null) {
            return;
        }
        $new = array_values(array_diff($blocked, (array) ($previous['blocked'] ?? [])));
        if ($new !== []) {
            $channel->deliver('Source health', 'Newly blocked sources: ' . implode(', ', $new) . '. Blocked now: ' . implode(', ', $blocked) . '.');
        }
    }

    /** @param array{generated_at: string, summary: array<string, int>, rows: list<array<string, mixed>>} $report */
    private function write(array $report): void
    {
        $directory = base_path('artifacts/reports');
        File::ensureDirectoryExists($directory);
        File::put($directory . '/source-health.json', (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $lines = ['# Source health', '', 'Generated: ' . $report['generated_at'], '', '| Source | Page | Verdict | Error | Reason |', '|---|---|---|---|---|'];
        foreach ($report['rows'] as $row) {
            $lines[] = sprintf('| %s | %s | %s | %s | %s |', $row['source'], $row['capability'], $row['verdict'], $row['error_code'] ?? '', str_replace('|', '/', mb_substr((string) $row['reason'], 0, 100)));
        }
        File::put($directory . '/source-health.md', implode("\n", $lines) . "\n");
    }
}
