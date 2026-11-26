<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PageRequest;
use App\Http\Requests\Api\V2\StoreDownloadItemRequest;
use App\Http\Requests\Api\V2\UpdateDownloadItemRequest;
use App\Services\Api\V2\Downloads\DownloadQueue;
use Illuminate\Http\JsonResponse;

final class DownloadItemController
{
    public function __construct(private readonly DownloadQueue $queue) {}

    public function status(): JsonResponse
    {
        return Envelope::success($this->queue->status());
    }

    public function index(PageRequest $request): JsonResponse
    {
        $result = $this->queue->page($request->page(), $request->perPage());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function store(StoreDownloadItemRequest $request): JsonResponse
    {
        $movie = $request->input('movie_code');
        $item = $this->queue->add((string) $request->input('magnet'), is_string($movie) ? $movie : null);

        return Envelope::success($item, status: 201, headers: ['Location' => '/api/v2/download-items/' . $item['hash']]);
    }

    public function show(string $hash): JsonResponse
    {
        return Envelope::success($this->queue->one($hash));
    }

    public function update(UpdateDownloadItemRequest $request, string $hash): JsonResponse
    {
        return Envelope::success($this->queue->setState($hash, (string) $request->input('state')));
    }

    public function destroy(string $hash): JsonResponse
    {
        $this->queue->remove($hash);

        return Envelope::empty();
    }

    public function links(string $hash): JsonResponse
    {
        return Envelope::success($this->queue->links($hash));
    }
}
