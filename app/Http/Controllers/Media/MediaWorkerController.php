<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Services\Media\MediaQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MediaWorkerController
{
    public function config(): JsonResponse
    {
        return response()->json(['data' => config('javmeta_media')]);
    }

    public function next(Request $request, MediaQueue $queue): JsonResponse
    {
        $v = $request->validate(['worker' => 'required|string|max:100']);
        return response()->json(['data' => $queue->claim($v['worker'])])->header('Cache-Control', 'no-store');
    }

    public function complete(int $id, Request $request, MediaQueue $queue): JsonResponse
    {
        $v = $request->validate([
            'claim_token' => 'required|uuid', 'result' => 'required|array',
            'result.duration' => 'sometimes|numeric|min:0',
            'result.thumbnails' => 'sometimes|array|max:10000',
            'result.thumbnails.*' => 'required|string|max:4096',
            'result.scenes' => 'sometimes|array|max:10000',
            'result.scenes.*.start_sec' => 'required|numeric|min:0',
            'result.scenes.*.end_sec' => 'required|numeric|gt:result.scenes.*.start_sec',
            'result.scenes.*.thumb_path' => 'required|string|max:4096',
        ]);
        $queue->finish($id, $v['claim_token'], $v['result'], null);
        return response()->json(['data' => ['accepted' => true]]);
    }

    public function fail(int $id, Request $request, MediaQueue $queue): JsonResponse
    {
        $v = $request->validate(['claim_token' => 'required|uuid', 'error' => 'required|string|max:4000']);
        $queue->finish($id, $v['claim_token'], null, $v['error']);
        return response()->json(['data' => ['accepted' => true]]);
    }
}
