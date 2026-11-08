<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\CalendarRequest;
use App\Services\Calendar\MovieCalendarService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

/** Release calendar: the movies released in a month, grouped by day (at most eight per day, plus the day total). */
final class MovieCalendarController extends BaseApiController
{
    public function index(CalendarRequest $request, MovieCalendarService $calendar): JsonResponse
    {
        $snapshot = $calendar->get($request->month());

        return $this->respondWithData(
            data: $snapshot->days,
            message: 'Release calendar retrieved successfully.',
            meta: $snapshot->meta(),
        );
    }
}
