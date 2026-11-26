<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Controllers\Api\V2\Concerns\KnowsTheActor;
use App\Http\Requests\Api\V2\UpdateSourceRequest;
use App\Services\Api\V2\Operations\SourceDirectory;
use Illuminate\Http\JsonResponse;

final class SourceController
{
    use KnowsTheActor;

    public function __construct(private readonly SourceDirectory $sources) {}

    public function index(): JsonResponse
    {
        return Envelope::success($this->sources->all());
    }

    public function show(string $slug): JsonResponse
    {
        return Envelope::success($this->sources->find($slug));
    }

    public function update(UpdateSourceRequest $request, string $slug): JsonResponse
    {
        return Envelope::success($this->sources->setEnabled($slug, $request->boolean('enabled'), $this->actorId()));
    }
}
