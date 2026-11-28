<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\ListDeliveriesRequest;
use App\Http\Requests\Api\V2\PutScheduleRequest;
use App\Services\Api\V2\Administration\Precondition;
use App\Services\Api\V2\Administration\ReportAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReportController
{
    public function __construct(private readonly ReportAdministration $reports) {}

    public function schedule(): JsonResponse
    {
        $schedule = $this->reports->schedule();

        return Envelope::success($schedule, headers: ['ETag' => Precondition::etag($schedule)]);
    }

    public function updateSchedule(PutScheduleRequest $request): JsonResponse
    {
        Precondition::assertMatches($request, $this->reports->schedule());
        $schedule = $this->reports->updateSchedule(Precondition::actor($request), $request->validated());

        return Envelope::success($schedule, headers: ['ETag' => Precondition::etag($schedule)]);
    }

    public function previews(): JsonResponse
    {
        return Envelope::success($this->reports->previews());
    }

    public function deliveries(ListDeliveriesRequest $request): JsonResponse
    {
        $page = (int) $request->query('page', 1);
        $perPage = (int) $request->query('per_page', 20);
        $result = $this->reports->deliveries($page, $perPage);

        return Envelope::success($result['data'], Envelope::pagination($page, $perPage, $result['total']));
    }

    public function send(Request $request): JsonResponse
    {
        $delivery = $this->reports->sendNow(Precondition::actor($request));

        return Envelope::success($delivery, status: 202, headers: ['Location' => "/api/v2/reports/deliveries/{$delivery['id']}"]);
    }

    public function show(string $type): JsonResponse
    {
        return Envelope::success($this->reports->data($type));
    }
}
