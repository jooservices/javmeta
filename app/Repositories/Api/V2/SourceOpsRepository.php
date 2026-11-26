<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Crawl sources with the counters the Command Center shows next to them, and the one switch an operator may flip. */
final class SourceOpsRepository
{
    private const COLUMNS = ['slug', 'name', 'base_url', 'enabled', 'priority', 'needs_proxy', 'browser_lane', 'gap_seconds_current', 'gap_seconds_default', 'consecutive_failures', 'circuit_state', 'last_success_at', 'last_error_at', 'last_error', 'circuit_open_count'];

    /** @return list<stdClass> */
    public function all(): array
    {
        return DB::table('sources')->orderBy('priority')->orderBy('slug')->get(self::COLUMNS)->all();
    }

    public function find(string $slug): ?stdClass
    {
        return DB::table('sources')->where('slug', $slug)->first(self::COLUMNS);
    }

    /** @return array<string, int> source slug => jobs claimed right now */
    public function claimedBySource(): array
    {
        return DB::table('crawl_queue')
            ->where('status', 'claimed')
            ->groupBy('source_slug')
            ->selectRaw('source_slug, COUNT(*) AS n')
            ->pluck('n', 'source_slug')
            ->map(static fn(mixed $n): int => (int) $n)
            ->all();
    }

    /** @return array<string, int> source slug => runs started since the given time */
    public function runsBySourceSince(CarbonInterface $since): array
    {
        return DB::table('crawl_runs')
            ->where('started_at', '>', $since)
            ->groupBy('source_slug')
            ->selectRaw('source_slug, COUNT(*) AS n')
            ->pluck('n', 'source_slug')
            ->map(static fn(mixed $n): int => (int) $n)
            ->all();
    }

    /** @return array{enabled: int, total: int, circuit_open: int, ok: int} */
    public function counts(): array
    {
        $row = DB::table('sources')->selectRaw("COALESCE(SUM(CASE WHEN enabled THEN 1 ELSE 0 END), 0) AS enabled, COUNT(*) AS total, COALESCE(SUM(CASE WHEN circuit_state <> 'closed' THEN 1 ELSE 0 END), 0) AS circuit_open, COALESCE(SUM(CASE WHEN enabled AND circuit_state = 'closed' THEN 1 ELSE 0 END), 0) AS ok")->first();

        return ['enabled' => (int) ($row->enabled ?? 0), 'total' => (int) ($row->total ?? 0), 'circuit_open' => (int) ($row->circuit_open ?? 0), 'ok' => (int) ($row->ok ?? 0)];
    }

    /** Switches the source and writes the transition as a crawler event in one transaction; returns the previous state, or null when there is no such source. */
    public function switchTo(string $slug, bool $enabled, string $by): ?bool
    {
        return DB::transaction(static function () use ($slug, $enabled, $by): ?bool {
            $row = DB::table('sources')->where('slug', $slug)->lockForUpdate()->first(['enabled']);
            if ($row === null) {
                return null;
            }

            $was = (bool) $row->enabled;
            if ($was !== $enabled) {
                DB::table('sources')->where('slug', $slug)->update(['enabled' => $enabled]);
                DB::table('crawl_events')->insert([
                    'source_slug' => $slug,
                    'kind' => 'source_toggled',
                    'url' => null,
                    'detail' => json_encode(['from' => $was, 'to' => $enabled, 'by' => $by], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return $was;
        });
    }
}
