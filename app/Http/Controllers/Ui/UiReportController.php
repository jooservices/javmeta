<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Services\Report\PeriodicReportService;
use Illuminate\Http\JsonResponse;

final class UiReportController
{
    public function show(PeriodicReportService $reports): JsonResponse
    {
        return response()->json(['data' => $reports->preview()]);
    }

    public function sendNow(PeriodicReportService $reports): JsonResponse
    {
        $result = $reports->sendNow();
        if (! $result['sent']) {
            return response()->json(['message' => 'The report was not delivered.', 'data' => $result], 502);
        }

        return response()->json(['data' => $result]);
    }
}
