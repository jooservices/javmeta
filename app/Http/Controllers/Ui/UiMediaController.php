<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Services\Media\MediaQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class UiMediaController
{
    public function index(Request $request, \App\Services\Downloads\FileLinkService $links): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $jobs = DB::table('media_jobs')->orderByDesc('id')->paginate(50, ['id', 'kind', 'library_file_id', 'status', 'attempts', 'result', 'error', 'created_at', 'started_at', 'finished_at'], 'page', $page);
        $jobs->through(static function ($job) use ($links): array {
            $row = (array) $job;
            $result = json_decode($row['result'] ?? '{}', true);
            unset($row['result']);
            $row['output_url'] = null;
            if ($job->kind === 'capture' && $job->status === 'done' && isset($result['capture_path'])) {
                try {
                    $row['output_url'] = $links->url($result['capture_path']);
                } catch (\Throwable) { /* File serving may be disabled. */
                }
            }
            return $row;
        });
        return response()->json(['capture_enabled' => (bool) config('javmeta_capture.enabled'), 'data' => $jobs]);
    }

    public function files(Request $request): JsonResponse
    {
        return response()->json(['data' => DB::table('library_files')->whereNull('missing_at')->orderBy('id')->paginate(50, ['id', 'code', 'path', 'size'])]);
    }

    public function store(Request $request, MediaQueue $queue): JsonResponse
    {
        $v = $request->validate(['kind' => ['required', Rule::in(MediaQueue::KINDS)], 'library_file_id' => 'required|integer|min:1']);
        return response()->json(['data' => ['id' => $queue->enqueue($v['kind'], $v['library_file_id'])]], 202);
    }

    public function capture(Request $request, MediaQueue $queue): JsonResponse
    {
        $v = $request->validate(['url' => 'required|url:http,https|max:4096']);
        return response()->json(['data' => ['id' => $queue->capture($v['url'])]], 202);
    }

    public function retry(int $id, MediaQueue $queue): JsonResponse
    {
        $queue->retry($id);
        return response()->json(['data' => ['accepted' => true]], 202);
    }
}
