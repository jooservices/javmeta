<?php

declare(strict_types=1);

namespace App\Http\Middleware;

/** Route identifiers that expose read-only public content while guest mode is enabled. */
final class GuestModeRoutePolicy
{
    /** @var list<string> */
    public const ALLOWED_ROUTES = [
        'GET|HEAD /',
        'GET|HEAD api/health',
        'GET|HEAD api/health/live',
        'GET|HEAD api/openapi.json',
        'GET|HEAD api/v1/status',
        'GET|HEAD app',
        'GET|HEAD login',
        'GET|HEAD ui-api/{path}',
        'GET|HEAD up',
    ];
}
