<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Models\Source;
use Illuminate\Console\Command;

final class SourcesCommand extends Command
{
    protected $signature = 'crawler:sources {--json : Print machine-readable JSON}';

    protected $description = 'List the crawl sources with their database switch, last success and last error.';

    public function handle(): int
    {
        $rows = [];
        foreach (Source::query()->orderBy('priority')->orderBy('slug')->get() as $source) {
            $rows[] = [
                'slug' => (string) $source->slug,
                'enabled' => (bool) $source->enabled,
                'last_success_at' => $source->last_success_at !== null ? (string) $source->last_success_at : null,
                'last_error_at' => $source->last_error_at !== null ? (string) $source->last_error_at : null,
                'last_error' => $source->last_error !== null ? mb_substr((string) $source->last_error, 0, 80) : null,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(['Source', 'Enabled', 'Last success', 'Last error at', 'Last error'], array_map(
            static fn(array $row): array => [$row['slug'], $row['enabled'] ? 'ON' : 'off', $row['last_success_at'] ?? '-', $row['last_error_at'] ?? '-', $row['last_error'] ?? ''],
            $rows,
        ));

        return self::SUCCESS;
    }
}
