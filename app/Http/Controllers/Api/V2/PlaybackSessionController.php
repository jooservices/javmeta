<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\StorePlaybackSessionRequest;
use App\Services\Api\V2\Downloads\PlaybackSessions;
use Illuminate\Http\JsonResponse;

final class PlaybackSessionController
{
    public function __construct(private readonly PlaybackSessions $sessions) {}

    public function store(StorePlaybackSessionRequest $request): JsonResponse
    {
        $session = $this->sessions->start((string) $request->input('movie_code'));

        return Envelope::success($session, status: 201, headers: ['Location' => '/api/v2/playback-sessions/' . $session['id']]);
    }
}
