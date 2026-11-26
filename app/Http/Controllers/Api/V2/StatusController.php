<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Operations\StatusSummary;
use Illuminate\Http\JsonResponse;

final class StatusController
{
    public function __construct(private readonly StatusSummary $summary) {}

    public function summary(): JsonResponse
    {
        return Envelope::success($this->summary->read());
    }
}
