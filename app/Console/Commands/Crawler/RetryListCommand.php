<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueRepository;
use App\Models\CrawlQueue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Console\Command;

final class RetryListCommand extends Command
{
    protected $signature = 'crawler:retry-list {--source=} {--round=}';

    protected $description = 'List pending crawl retry rows.';

    public function handle(): int
    {
        $query = app(CrawlQueueRepository::class)->modelQuery(CrawlQueue::class)->where('status', CrawlQueue::STATUS_RETRY_PENDING)->orderBy('next_attempt_at')->orderBy('id');
        if (is_string($this->option('source')) && $this->option('source') !== '') {
            $query->where('source_slug', (string) $this->option('source'));
        }
        if ($this->option('round') !== null) {
            $query->where('retry_round', max(1, (int) $this->option('round')));
        }

        /** @var Collection<int, CrawlQueue> $rows */
        $rows = $query->limit(500)->get(['id', 'source_slug', 'kind', 'url', 'retry_round', 'attempts', 'next_attempt_at', 'error_code']);
        $this->table(['ID', 'Source', 'Kind', 'URL', 'Round', 'Attempts', 'Next attempt', 'Error'], $rows->map(fn(CrawlQueue $row): array => [
            $row->getKey(), $row->source_slug, $row->kind, $this->safeUrl((string) $row->url), $row->retry_round, $row->attempts,
            Carbon::parse((string) $row->getAttribute('next_attempt_at'))->toDateTimeString(), $row->error_code,
        ])->all());
        $this->info('Showing ' . $rows->count() . ' retry row(s), maximum 500.');

        return self::SUCCESS;
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '[invalid URL]';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . ($parts['path'] ?? '');
    }
}
