<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PageRequest;
use App\Http\Requests\Api\V2\StoreMediaJobRequest;
use App\Http\Requests\Api\V2\UpdateMediaJobRequest;
use App\Services\Api\V2\Downloads\MediaJobs;
use Illuminate\Http\JsonResponse;

final class MediaJobController
{
    public function __construct(private readonly MediaJobs $jobs) {}

    public function index(PageRequest $request): JsonResponse
    {
        $result = $this->jobs->page($request->page(), $request->perPage());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function store(StoreMediaJobRequest $request): JsonResponse
    {
        $url = $request->input('source_url');
        $params = $request->input('params');
        $job = $this->jobs->create((string) $request->input('kind'), is_string($url) ? $url : null, is_array($params) ? $params : []);

        return Envelope::success($job, status: 201, headers: ['Location' => '/api/v2/media-jobs/' . $job['id']]);
    }

    public function update(UpdateMediaJobRequest $request, int $id): JsonResponse
    {
        return Envelope::success($this->jobs->retry($id));
    }
}
