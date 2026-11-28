<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PageRequest;
use App\Services\Api\V2\Downloads\LibraryOverview;
use Illuminate\Http\JsonResponse;

final class LibraryController
{
    public function __construct(private readonly LibraryOverview $library) {}

    public function summary(): JsonResponse
    {
        return Envelope::success($this->library->summary());
    }

    public function files(PageRequest $request): JsonResponse
    {
        $result = $this->library->files($request->match(), $request->page(), $request->perPage());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function codes(): JsonResponse
    {
        return Envelope::success($this->library->codes());
    }

    public function mediaFiles(): JsonResponse
    {
        return Envelope::success($this->library->mediaFiles());
    }
}
