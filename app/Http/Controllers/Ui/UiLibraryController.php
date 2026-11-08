<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Requests\UnmatchedLibraryRequest;
use App\Services\Library\UiLibraryService;
use Illuminate\Http\JsonResponse;

final class UiLibraryController
{
    public function summary(UiLibraryService $library): JsonResponse
    {
        return response()->json(['data' => $library->summary()->toArray()]);
    }

    public function codes(UiLibraryService $library): JsonResponse
    {
        return response()->json(['data' => $library->codes()->toArray()]);
    }

    public function unmatched(UnmatchedLibraryRequest $request, UiLibraryService $library): JsonResponse
    {
        return response()->json($library->unmatched($request->page())->toArray());
    }

    public function onDisk(UiLibraryService $library): JsonResponse
    {
        return response()->json(['data' => $library->onDisk()->toArray()]);
    }
}
