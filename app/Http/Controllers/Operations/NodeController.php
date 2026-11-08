<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use App\Http\Requests\Nodes\CreateNodeEnrollmentRequest;
use App\Services\Nodes\NodeEnrollmentService;
use Illuminate\Http\JsonResponse;

/** Command Center: create a one-time permission for a new node and list recent ones. */
final class NodeController
{
    public function index(NodeEnrollmentService $enrollments): JsonResponse
    {
        return response()->json(['data' => $enrollments->recent()])->header('Cache-Control', 'no-store, private');
    }

    public function store(CreateNodeEnrollmentRequest $request, NodeEnrollmentService $enrollments): JsonResponse
    {
        $grant = $enrollments->create(
            (string) $request->validated('node_name'),
            (string) $request->validated('role'),
            $request->user()?->id,
        );

        return response()->json(['data' => $grant->toArray()], 201)->header('Cache-Control', 'no-store, private');
    }
}
