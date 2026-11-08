<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Services\Media\SceneSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SceneSearchController
{
    public function __invoke(Request $request, SceneSearchService $search): JsonResponse
    {
        $v = $request->validate(['q' => 'required|string|min:3|max:200', 'per_page' => 'sometimes|integer|min:1|max:50']);
        return response()->json(['data' => $search->search($v['q'], (int) ($v['per_page'] ?? 20))]);
    }
}
