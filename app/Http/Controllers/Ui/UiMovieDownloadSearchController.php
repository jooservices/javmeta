<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Requests\SearchMovieDownloadsRequest;
use App\Services\Downloads\DownloadLinkSearchService;
use Illuminate\Http\JsonResponse;

/**
 * Public (guest + owner) on-demand magnet/torrent search for a movie.
 */
final class UiMovieDownloadSearchController
{
    public function store(
        SearchMovieDownloadsRequest $request,
        string $code,
        DownloadLinkSearchService $searches,
    ): JsonResponse {
        $sessionId = '';
        try {
            $sessionId = (string) $request->session()->getId();
        } catch (\Throwable) {
            $sessionId = '';
        }
        $clientKey = trim((string) ($request->ip() ?? 'unknown'))
            . '|' . substr(hash('sha256', $sessionId !== '' ? $sessionId : (string) $request->userAgent()), 0, 16);

        $result = $searches->start($code, $clientKey);
        $status = match ($result->reason) {
            'budget', 'cooldown' => 429,
            'disabled' => 403,
            default => 202,
        };

        $message = match ($result->reason) {
            'budget' => __('downloads.search.budget_exhausted'),
            'cooldown' => __('downloads.search.cooldown'),
            'disabled' => __('downloads.search.disabled'),
            'already_running' => __('downloads.search.already_running'),
            'no_sources' => __('downloads.search.no_sources'),
            default => __('downloads.search.started'),
        };

        return response()->json([
            'message' => $message,
            'data' => [
                'queued' => $result->queued,
                'reason' => $result->reason,
                'downloads' => $result->summary->toArray(),
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
