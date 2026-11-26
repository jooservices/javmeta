<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\StoreDownloadRequestRequest;
use App\Services\Api\V2\Downloads\DownloadRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DownloadRequestController
{
    public function __construct(private readonly DownloadRequests $requests) {}

    public function index(Request $request): JsonResponse
    {
        return Envelope::success($this->requests->mine($this->requester($request)));
    }

    public function store(StoreDownloadRequestRequest $request): JsonResponse
    {
        $result = $this->requests->ask($this->requester($request), (string) $request->input('movie_code'));
        $headers = ['Location' => '/api/v2/download-requests/' . $result['request']['id']];

        return Envelope::success($result['request'], status: $result['created'] ? 201 : 200, headers: $headers);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return Envelope::success($this->requests->one($id, $this->requester($request)));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requests->cancel($id, $this->requester($request));

        return Envelope::empty();
    }

    private function requester(Request $request): string
    {
        $id = Auth::guard('web')->id();

        return DownloadRequests::requester(is_numeric($id) ? (int) $id : null, (string) $request->ip());
    }
}
