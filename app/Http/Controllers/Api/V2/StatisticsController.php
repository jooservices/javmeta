<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Discovery\StatisticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StatisticsController
{
    public function __construct(private readonly StatisticsService $statistics) {}

    public function overview(): JsonResponse
    {
        return Envelope::success($this->statistics->overview());
    }

    public function series(Request $request): JsonResponse
    {
        $result = $this->statistics->series((string) $request->query('metric', 'movies'), (string) $request->query('interval', 'day'), (int) $request->query('days', 30) ?: 30);

        return Envelope::success($result['data'], $result['meta']);
    }

    public function distributions(Request $request): JsonResponse
    {
        $result = $this->statistics->distribution((string) $request->query('by', ''), (int) $request->query('limit', 20) ?: 20);

        return Envelope::success($result['data'], $result['meta']);
    }

    public function correlations(Request $request): JsonResponse
    {
        $result = $this->statistics->correlation((string) $request->query('pair', 'age-movies'));

        return Envelope::success($result['data'], $result['meta']);
    }

    public function dataQuality(): JsonResponse
    {
        return Envelope::success($this->statistics->dataQuality());
    }

    public function crawlHealth(Request $request): JsonResponse
    {
        return Envelope::success($this->statistics->crawlHealth((int) $request->query('hours', 72) ?: 72));
    }

    public function contributions(): JsonResponse
    {
        return Envelope::success($this->statistics->contributions());
    }

    public function sourcesActive(): JsonResponse
    {
        return Envelope::success($this->statistics->sourcesActive());
    }

    public function dashboard(): JsonResponse
    {
        return Envelope::success($this->statistics->dashboard());
    }

    public function compare(Request $request): JsonResponse
    {
        return Envelope::success($this->statistics->compare((string) $request->query('kind', ''), (string) $request->query('names', '')));
    }
}
