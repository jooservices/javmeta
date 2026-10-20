<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class ReportController extends BaseApiController
{
    public function show(string $type, ReportService $reports): JsonResponse
    {
        if (! in_array($type, ['quality', 'cost', 'sources'], true)) {
            return $this->respondWithError('Unknown report. Use quality, cost or sources.', 404);
        }

        $data = Cache::remember('admin-report:' . $type, 60, static fn(): array => match ($type) {
            'quality' => $reports->quality(),
            'cost' => $reports->cost(),
            default => $reports->sources(),
        });

        return $this->respondWithData($data);
    }
}
