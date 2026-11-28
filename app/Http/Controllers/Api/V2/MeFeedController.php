<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\MarkFeedSeenRequest;
use App\Http\Requests\Api\V2\MeListRequest;
use App\Services\Api\V2\Me\Feed;
use Illuminate\Http\JsonResponse;

final class MeFeedController
{
    public function __construct(private readonly Feed $feed) {}

    public function index(MeListRequest $request): JsonResponse
    {
        $result = $this->feed->page(Caller::userId(), $request->validated());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function seen(MarkFeedSeenRequest $request): JsonResponse
    {
        $until = $request->input('seen_until');

        return Envelope::success($this->feed->markSeen(Caller::userId(), is_string($until) ? $until : null));
    }
}
