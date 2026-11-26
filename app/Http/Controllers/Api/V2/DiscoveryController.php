<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\Paging;
use App\Http\Requests\Api\V2\CalendarRequest;
use App\Services\Api\V2\Discovery\UpdatesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DiscoveryController
{
    public function __construct(private readonly UpdatesService $updates) {}

    public function updates(Request $request): JsonResponse
    {
        [$page, $perPage] = Paging::read($request->query(), 48, 120);
        $result = $this->updates->updates($request->query(), $page, $perPage);

        return Envelope::success($result['data'], $result['meta']);
    }

    public function summary(): JsonResponse
    {
        return Envelope::success($this->updates->summary());
    }

    public function highlights(): JsonResponse
    {
        return Envelope::success($this->updates->highlights());
    }

    public function calendar(CalendarRequest $request): JsonResponse
    {
        $result = $this->updates->calendar($request->query('month'), $request->query('day'));

        return Envelope::success($result['data'], $result['meta']);
    }
}
