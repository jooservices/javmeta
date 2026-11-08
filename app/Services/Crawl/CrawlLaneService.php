<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Repositories\SourceRepository;

/** Source routing is a snapshot: no fetch-history queries on the dispatch path. */
final class CrawlLaneService
{
    public function __construct(private readonly ?SourceRepository $sources = null) {}

    /** @return list<string> */
    public function browserSources(): array
    {
        $computed = $this->repository()->browserLaneSlugs();
        $browser = [...(array) config('javmeta_queues.browser_sources', []), ...$computed, ...(array) config('javmeta_queues.lane_browser_pins', [])];

        // A direct pin also lets an operator override the static browser list.
        return array_values(array_diff(array_unique(array_filter($browser, 'is_string')), (array) config('javmeta_queues.lane_direct_pins', [])));
    }

    public function lane(string $slug): string
    {
        return config('javmeta_queues.lanes_enabled', true) && in_array($slug, $this->browserSources(), true) ? 'browser' : 'direct';
    }

    public function recompute(): void
    {
        $minimum = max(1, (int) config('javmeta_queues.lane_min_attempts', 40));
        $share = max(0, min(100, (int) config('javmeta_queues.lane_browser_percent', 25)));
        $rows = $this->repository()->fetchMethodTotals(now()->subDay());
        $slugs = [];
        foreach ($rows as $row) {
            if ((int) $row->total >= $minimum && (int) $row->browser * 100 >= (int) $row->total * $share) {
                $slugs[] = (string) $row->source_slug;
            }
        }
        $this->repository()->replaceBrowserLaneSlugs($slugs);
    }

    private function repository(): SourceRepository
    {
        return $this->sources ?? app(SourceRepository::class);
    }
}
