<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** What the library page shows: totals, disk use by folder, the movies on disk, the files that matched nothing. */
final class UiLibraryController
{
    public function summary(): JsonResponse
    {
        $present = DB::table('library_files')->whereNull('missing_at');
        $byFolder = (clone $present)->selectRaw('folder, COUNT(*) AS files, SUM(size) AS bytes')->groupBy('folder')->orderByDesc('bytes')->get()
            ->map(static fn($r): array => ['folder' => $r->folder, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all();

        return response()->json(['data' => [
            'files' => (int) (clone $present)->count(),
            'matched' => (int) (clone $present)->whereNotNull('movie_id')->count(),
            'unmatched' => (int) (clone $present)->whereNull('movie_id')->count(),
            'missing' => (int) DB::table('library_files')->whereNotNull('missing_at')->count(),
            'bytes' => (int) (clone $present)->sum('size'),
            'by_folder' => $byFolder,
            'last_scan_at' => DB::table('library_files')->max('last_seen_at'),
            'configured' => ! empty(array_filter((array) config('javmeta_library.folders', []))),
        ]]);
    }

    /** Display codes of every movie that has a file on disk (the UI marks them with a badge). */
    public function codes(): JsonResponse
    {
        return response()->json(['data' => DB::table('library_files')->whereNull('missing_at')->whereNotNull('code')->distinct()->pluck('code')->all()]);
    }

    public function unmatched(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $query = DB::table('library_files')->whereNull('missing_at')->whereNull('movie_id');

        return response()->json(['data' => (clone $query)->orderBy('path')->forPage($page, 100)->get(['path', 'size', 'folder'])->all(), 'meta' => ['total' => (int) $query->count(), 'page' => $page, 'per_page' => 100]]);
    }

    public function onDisk(Request $request): JsonResponse
    {
        $codes = DB::table('library_files')->whereNull('missing_at')->whereNotNull('code')->select('code', DB::raw('COUNT(*) AS files'), DB::raw('SUM(size) AS bytes'))->groupBy('code')->orderBy('code')->limit(500)->get();

        return response()->json(['data' => $codes->map(static fn($r): array => ['code' => $r->code, 'files' => (int) $r->files, 'bytes' => (int) $r->bytes])->all()]);
    }
}
