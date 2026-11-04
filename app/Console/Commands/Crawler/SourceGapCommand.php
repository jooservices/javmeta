<?php

declare(strict_types=1);

namespace App\Console\Commands\Crawler;

use App\Repositories\CrawlQueueServiceRepository;
use App\Models\Source;
use App\Services\Crawl\SourceGapPolicy;
use Illuminate\Console\Command;

final class SourceGapCommand extends Command
{
    protected $signature = 'crawler:source-gap {slug? : Optional source slug to show} {--json : Print machine-readable JSON}';

    protected $description = 'Show source gap definitions, overrides, effective values, and adaptive gaps.';

    public function handle(SourceGapPolicy $gapPolicy): int
    {
        $sources = app(CrawlQueueServiceRepository::class)->modelQuery(Source::class)->get()->keyBy('slug');
        $definitions = (array) config('javmeta_sources.sources', []);
        $overrides = (array) config('javmeta_queues.source_gap_overrides', []);
        $slugs = array_values(array_unique([
            ...array_keys($definitions),
            ...$sources->keys()->all(),
            ...array_keys($overrides),
        ]));
        sort($slugs);

        $requested = $this->argument('slug');
        if (is_string($requested)) {
            if (! in_array($requested, $slugs, true)) {
                $this->error("Unknown source [{$requested}].");

                return self::FAILURE;
            }
            $slugs = [$requested];
        }

        $rows = [];
        foreach ($slugs as $slug) {
            $source = $sources->get($slug);
            $gap = $gapPolicy->describe($slug, $source instanceof Source ? $source : null);
            $rows[] = [
                'source' => $slug,
                'definition' => $gap['definition'],
                'override' => $gap['override'],
                'effective' => $gap['effective'],
                'current_adaptive' => $source instanceof Source ? $gapPolicy->adaptiveGap($source) : null,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Source', 'Definition min/default/max (s)', 'Override min/default/max (s)', 'Effective min/default/max (s)', 'Current adaptive (s)'],
            array_map(fn(array $row): array => [
                $row['source'],
                $this->range($row['definition']),
                $row['override'] === null ? '-' : $this->range($row['override']),
                $this->range($row['effective']),
                $row['current_adaptive'] === null ? '-' : $this->format($row['current_adaptive']),
            ], $rows),
        );

        return self::SUCCESS;
    }

    /** @param array{min: float, default: float, max: float} $range */
    private function range(array $range): string
    {
        return implode(' / ', [
            $this->format($range['min']),
            $this->format($range['default']),
            $this->format($range['max']),
        ]);
    }

    private function format(float $gap): string
    {
        return rtrim(rtrim(number_format($gap, 2, '.', ''), '0'), '.');
    }
}
