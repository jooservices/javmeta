<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use stdClass;

/** What each crawler node did, read from the fetch attempts, and the join codes handed to new nodes. */
final class NodeOpsRepository
{
    /** @return list<stdClass> one row per node and lane over the window */
    public function lanesSince(CarbonInterface $since): array
    {
        return DB::table('crawl_fetch_attempts')
            ->where('created_at', '>', $since)
            ->whereNotNull('node')
            ->groupBy('node', 'lane')
            ->orderBy('node')
            ->orderBy('lane')
            ->selectRaw("node, lane, COUNT(*) AS attempts, SUM(CASE WHEN outcome = 'ok' THEN 1 ELSE 0 END) AS ok, AVG(duration_ms) AS avg_ms, MAX(created_at) AS last_at, COUNT(DISTINCT egress_ip) AS egress_ips")
            ->get()
            ->all();
    }

    /** @return list<stdClass> attempts and successes per node over the window */
    public function totalsSince(CarbonInterface $since): array
    {
        return DB::table('crawl_fetch_attempts')
            ->where('created_at', '>', $since)
            ->whereNotNull('node')
            ->groupBy('node')
            ->selectRaw("node, COUNT(*) AS attempts, SUM(CASE WHEN outcome = 'ok' THEN 1 ELSE 0 END) AS ok")
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function hourlyOfNode(string $node, CarbonInterface $since): array
    {
        $hour = DB::getDriverName() === 'pgsql' ? "date_trunc('hour', created_at)" : "strftime('%Y-%m-%d %H:00:00', created_at)";

        return DB::table('crawl_fetch_attempts')
            ->where('node', $node)
            ->where('created_at', '>', $since)
            ->groupBy(DB::raw($hour))
            ->orderBy(DB::raw($hour))
            ->selectRaw("{$hour} AS hour_at, COUNT(*) AS attempts, SUM(CASE WHEN outcome = 'ok' THEN 1 ELSE 0 END) AS ok, AVG(duration_ms) AS avg_ms")
            ->get()
            ->all();
    }

    /** @return list<stdClass> the sources a node worked on, busiest first */
    public function contributionOfNode(string $node, CarbonInterface $since): array
    {
        return DB::table('crawl_fetch_attempts')
            ->where('node', $node)
            ->where('created_at', '>', $since)
            ->groupBy('source_slug')
            ->orderByDesc('n')
            ->limit(10)
            ->selectRaw('source_slug, COUNT(*) AS n')
            ->get()
            ->all();
    }

    /** @return list<stdClass> */
    public function enrollments(int $limit): array
    {
        return DB::table('node_enrollments')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'node_name', 'role', 'expires_at', 'used_at', 'used_from_ip', 'created_at'])
            ->all();
    }

    /** Deletes a join code that was not used yet; a used or unknown code stays as it is. */
    public function revokeUnused(int $id): int
    {
        return DB::table('node_enrollments')->where('id', $id)->whereNull('used_at')->delete();
    }
}
