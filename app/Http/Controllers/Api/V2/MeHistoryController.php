<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\MeListRequest;
use App\Http\Requests\Api\V2\RecordViewRequest;
use App\Services\Api\V2\Me\ViewHistory;
use Illuminate\Http\JsonResponse;

final class MeHistoryController
{
    public function __construct(private readonly ViewHistory $history) {}

    public function index(MeListRequest $request): JsonResponse
    {
        $result = $this->history->page(Caller::userId(), $request->validated());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function store(RecordViewRequest $request): JsonResponse
    {
        $label = $request->input('label');
        $entry = $this->history->record(Caller::userId(), (string) $request->input('kind'), (string) $request->input('ref'), is_string($label) ? $label : null);

        return Envelope::success($entry, status: 201, headers: ['Location' => '/api/v2/me/history']);
    }

    public function destroy(): JsonResponse
    {
        $this->history->clear(Caller::userId());

        return Envelope::empty();
    }
}
