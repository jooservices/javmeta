<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class DashboardController
{
    public function index(): View
    {
        return view('operations.dashboard');
    }

    public function data(): JsonResponse
    {
        $since = now()->subHour();
        $queue = DB::table('crawl_queue')
            ->select('source_slug', 'status', DB::raw('COUNT(*) AS total'))
            ->groupBy('source_slug', 'status')->get();
        $queueTotals = ['pending' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0];
        $queueBySource = [];
        foreach ($queue as $row) {
            $status = (string) $row->status;
            $slug = (string) $row->source_slug;
            $count = (int) $row->total;
            $queueTotals[$status] = ($queueTotals[$status] ?? 0) + $count;
            $queueBySource[$slug][$status] = $count;
        }

        $runs = DB::table('crawl_runs')->where('started_at', '>=', $since)
            ->selectRaw('COUNT(*) AS total, COALESCE(SUM(pages_fetched), 0) AS pages, COALESCE(SUM(movies_new), 0) AS movies_new, COALESCE(SUM(movies_updated), 0) AS movies_updated, COALESCE(SUM(failures), 0) AS failures')
            ->first();
        $sources = DB::table('sources')->where('enabled', true)->orderBy('priority')
            ->get(['slug', 'name', 'last_success_at', 'last_error_at', 'last_error', 'circuit_state', 'consecutive_failures'])
            ->map(static function ($source) use ($queueBySource): array {
                $lastSuccess = $source->last_success_at === null ? null : Carbon::parse($source->last_success_at);
                $age = $lastSuccess === null ? null : (int) $lastSuccess->diffInSeconds(now());
                $status = $age === null || $age > 1800 || $source->circuit_state !== 'closed' ? 'attention' : 'healthy';

                return [
                    'slug' => (string) $source->slug,
                    'name' => (string) $source->name,
                    'status' => $status,
                    'last_success_at' => $lastSuccess?->toIso8601String(),
                    'last_success_age_seconds' => $age,
                    'last_error_at' => $source->last_error_at,
                    'last_error' => mb_strimwidth((string) ($source->last_error ?? ''), 0, 220, '…'),
                    'circuit_state' => (string) $source->circuit_state,
                    'consecutive_failures' => (int) $source->consecutive_failures,
                    'queue' => $queueBySource[(string) $source->slug] ?? [],
                ];
            });

        $issues = DB::table('crawl_queue')->where('status', 'failed')->orderByDesc('id')->limit(12)
            ->get(['source_slug', 'kind', 'error_code', 'attempts', 'last_error'])
            ->map(static fn($row): array => [
                'source' => (string) $row->source_slug,
                'kind' => (string) $row->kind,
                'code' => (string) ($row->error_code ?? 'unknown'),
                'attempts' => (int) $row->attempts,
                'detail' => mb_strimwidth((string) ($row->last_error ?? ''), 0, 220, '…'),
            ]);

        $events = DB::table('crawl_events')->orderByDesc('id')->limit(12)
            ->get(['source_slug', 'kind', 'created_at'])
            ->map(static fn($row): array => [
                'source' => (string) $row->source_slug,
                'kind' => (string) $row->kind,
                'at' => $row->created_at,
            ]);

        return response()->json([
            'as_of' => now()->toIso8601String(),
            'totals' => [
                'movies' => DB::table('movies')->count(),
                'performers' => DB::table('performers')->count(),
                'movies_first_seen_1h' => DB::table('movies')->where('first_seen_at', '>=', $since)->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'queue' => $queueTotals,
            'hour' => [
                'runs' => (int) ($runs->total ?? 0),
                'pages' => (int) ($runs->pages ?? 0),
                'movies_new' => (int) ($runs->movies_new ?? 0),
                'movies_updated' => (int) ($runs->movies_updated ?? 0),
                'failures' => (int) ($runs->failures ?? 0),
            ],
            'sources' => $sources,
            'issues' => $issues,
            'events' => $events,
        ])->header('Cache-Control', 'no-store, private');
    }
}
