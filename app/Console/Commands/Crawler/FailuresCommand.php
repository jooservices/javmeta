<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\CrawlQueue;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

final class FailuresCommand extends Command
{
    protected $signature = 'crawler:failures {--since=24h} {--source=} {--json}';

    protected $description = 'Count recent failed crawl rows by source and error code.';

    public function handle(): int
    {
        try {
            $since = $this->parseSince((string) $this->option('since'));
        } catch (Throwable) {
            $this->components->error('Invalid --since value. Use a duration such as 24h or an ISO date.');

            return self::FAILURE;
        }

        $source = trim((string) $this->option('source'));
        $source = $source === '' ? null : $source;

        $failures = CrawlQueue::query()
            ->where('status', CrawlQueue::STATUS_FAILED)
            ->where('last_failed_at', '>=', $since)
            ->when($source !== null, static fn($query) => $query->where('source_slug', $source))
            ->select('source_slug', 'error_code')
            ->selectRaw('COUNT(*) as failures')
            ->groupBy('source_slug', 'error_code')
            ->orderBy('source_slug')
            ->orderBy('error_code')
            ->get()
            ->map(static fn(CrawlQueue $row): array => [
                'source' => $row->source_slug,
                'error_code' => $row->error_code,
                'count' => (int) $row->getAttribute('failures'),
            ])
            ->all();

        $total = array_sum(array_column($failures, 'count'));

        if ($this->option('json')) {
            $this->line(json_encode([
                'since' => $since->toIso8601String(),
                'source' => $source,
                'total' => $total,
                'failures' => $failures,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($failures === []) {
            $this->components->info('No crawl failures found for the selected period.');

            return self::SUCCESS;
        }

        $this->table(['Source', 'Error code', 'Count'], array_map(
            static fn(array $failure): array => [$failure['source'], $failure['error_code'], $failure['count']],
            $failures,
        ));
        $this->line("Total: {$total}");

        return self::SUCCESS;
    }

    private function parseSince(string $value): CarbonImmutable
    {
        if (preg_match('/^(\d+)\s*([smhdw])$/i', trim($value), $matches) === 1) {
            $amount = (int) $matches[1];
            $seconds = $amount * match (strtolower($matches[2])) {
                's' => 1,
                'm' => 60,
                'h' => 3600,
                'd' => 86400,
                'w' => 604800,
                default => throw new LogicException('Unsupported duration unit.'),
            };

            return CarbonImmutable::now()->subSeconds($seconds);
        }

        return CarbonImmutable::parse($value);
    }
}
