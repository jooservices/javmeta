<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Requests\ToggleUiMarkRequest;
use App\Http\Requests\UiMarkedMoviesRequest;
use App\Services\Ui\UiMarksService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UiMarksController
{
    public function index(Request $request, UiMarksService $marks): JsonResponse
    {
        $kind = $request->query('kind');
        $subject = $request->query('subject');

        return response()->json($marks->index(
            (int) $request->user()->id,
            is_string($kind) ? $kind : null,
            is_string($subject) ? $subject : null,
        )->toArray());
    }

    public function toggle(ToggleUiMarkRequest $request, UiMarksService $marks): JsonResponse
    {
        /** @var array{kind: string, subject: string, key: string, on: bool|int|string} $data */
        $data = $request->validated();

        return response()->json($marks->toggle(
            (int) $request->user()->id,
            $data['kind'],
            $data['subject'],
            $data['key'],
            $data['on'],
        )->toArray());
    }

    public function movies(UiMarkedMoviesRequest $request, UiMarksService $marks, string $kind): JsonResponse
    {
        return response()->json($marks->movies((int) $request->user()->id, $kind)->toArray());
    }

    public function feed(Request $request, UiMarksService $marks): JsonResponse
    {
        return response()->json($marks->feed((int) $request->user()->id)->toArray());
    }

    public function feedSeen(Request $request, UiMarksService $marks): JsonResponse
    {
        return response()->json($marks->feedSeen((int) $request->user()->id)->toArray());
    }
}
