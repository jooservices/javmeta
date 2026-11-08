<?php

declare(strict_types=1);

namespace App\Http\Controllers\Nodes;

use App\Http\Requests\Nodes\RedeemNodeEnrollmentRequest;
use App\Services\Nodes\NodeEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** The part of node enrollment a new VM calls: fetch the join script and redeem its token. Both are public; the token is the credential. */
final class NodeEnrollmentController
{
    public function script(): Response
    {
        $script = (string) file_get_contents(base_path('deploy/join-node.sh'));

        return response(str_replace('@SERVER_URL@', rtrim((string) config('app.url'), '/'), $script), 200, [
            'Content-Type' => 'text/x-shellscript; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function redeem(RedeemNodeEnrollmentRequest $request, NodeEnrollmentService $enrollments): JsonResponse
    {
        $bundle = $enrollments->redeem(
            (string) $request->validated('token'),
            (string) $request->validated('node_name'),
            (string) $request->ip(),
        );
        // One answer for unknown, wrong node, used and expired: the caller learns nothing about which.
        if ($bundle === null) {
            return response()->json(['message' => 'Not found.'], 404)->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $bundle->toArray()])->header('Cache-Control', 'no-store, private');
    }
}
