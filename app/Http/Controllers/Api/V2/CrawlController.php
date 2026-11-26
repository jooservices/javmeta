<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Operations\CrawlLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CrawlController
{
    public function __construct(private readonly CrawlLog $crawl) {}

    public function runs(Request $request): JsonResponse
    {
        $page = $this->crawl->runs($request->query());

        return Envelope::success($page['data'], $page['meta']);
    }

    public function run(int $id): JsonResponse
    {
        return Envelope::success($this->crawl->find($id));
    }

    public function attempts(int $id): JsonResponse
    {
        return Envelope::success($this->crawl->attempts($id));
    }

    public function queue(): JsonResponse
    {
        return Envelope::success($this->crawl->queue());
    }

    public function errors(): JsonResponse
    {
        return Envelope::success($this->crawl->errors());
    }
}
