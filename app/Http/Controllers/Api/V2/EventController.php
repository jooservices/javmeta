<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Services\Api\V2\Operations\EventFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class EventController
{
    public function __construct(private readonly EventFeed $events) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->events->page($request->query());

        return Envelope::success($page['data'], $page['meta']);
    }

    /** Server-Sent Events. A stream lasts a few minutes, then the client reconnects with Last-Event-ID (EventSource does it by itself). */
    public function stream(Request $request): StreamedResponse
    {
        $lastId = (string) $request->header('Last-Event-ID', '');
        $polls = max(1, intdiv((int) config('javmeta_ops.stream_seconds', 300), EventFeed::POLL_SECONDS));
        $frames = $this->events->frames(ctype_digit($lastId) ? (int) $lastId : null, $polls);

        return new StreamedResponse(static function () use ($frames): void {
            foreach ($frames as $frame) {
                echo $frame;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'X-Accel-Buffering' => 'no']);
    }
}
