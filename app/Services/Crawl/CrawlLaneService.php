<?php

declare(strict_types=1);

namespace App\Services\Crawl;

use App\Models\Source;
use Illuminate\Support\Facades\DB;

/** Source routing is a snapshot: no fetch-history queries on the dispatch path. */
final class CrawlLaneService
{
    /** @return list<string> */
    public function browserSources(): array
    {
        $computed = Source::query()->where('browser_lane', true)->pluck('slug')->all();
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
        $rows = DB::table('crawl_fetch_attempts')
            ->where('created_at', '>=', now()->subDay())
            ->where('outcome', '!=', 'skipped')
            ->select('source_slug')
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN method IN (?, ?) THEN 1 ELSE 0 END) AS browser', ['flaresolverr', 'playwright'])
            ->groupBy('source_slug')->get();
        $slugs = [];
        foreach ($rows as $row) {
            if ((int) $row->total >= $minimum && (int) $row->browser * 100 >= (int) $row->total * $share) {
                $slugs[] = (string) $row->source_slug;
            }
        }
        DB::transaction(static function () use ($slugs): void {
            Source::query()->where('browser_lane', true)->update(['browser_lane' => false]);
            Source::query()->whereIn('slug', $slugs)->update(['browser_lane' => true]);
        });
    }
}
