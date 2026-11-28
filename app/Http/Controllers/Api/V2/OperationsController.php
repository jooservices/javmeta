<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Operations\InfrastructureReader;
use App\Services\Api\V2\Operations\OperationsOverview;
use Illuminate\Http\JsonResponse;

final class OperationsController
{
    public function __construct(
        private readonly OperationsOverview $overview,
        private readonly InfrastructureReader $infrastructure,
    ) {}

    public function overview(): JsonResponse
    {
        return Envelope::success($this->overview->overview());
    }

    public function performance(): JsonResponse
    {
        return Envelope::success($this->overview->performance());
    }

    public function infrastructure(): JsonResponse
    {
        $snapshot = $this->infrastructure->read();

        return Envelope::success($snapshot['data'], $snapshot['meta']);
    }
}
