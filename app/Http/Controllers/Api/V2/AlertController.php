<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Controllers\Api\V2\Concerns\KnowsTheActor;
use App\Http\Requests\Api\V2\ResolveReviewFlagRequest;
use App\Services\Api\V2\Operations\AlertsAndFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AlertController
{
    use KnowsTheActor;

    public function __construct(private readonly AlertsAndFlags $alerts) {}

    public function alerts(Request $request): JsonResponse
    {
        $page = $this->alerts->alerts($request->query());

        return Envelope::success($page['data'], $page['meta']);
    }

    public function flags(Request $request): JsonResponse
    {
        $page = $this->alerts->flags($request->query());

        return Envelope::success($page['data'], $page['meta']);
    }

    public function resolveFlag(ResolveReviewFlagRequest $request, int $id): JsonResponse
    {
        $reason = $request->input('reason');

        return Envelope::success($this->alerts->resolve($id, is_string($reason) ? $reason : null, $this->actorId()));
    }
}
