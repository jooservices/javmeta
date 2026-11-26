<?php

declare(strict_types=1);

namespace App\Http\Api\V2;

use Illuminate\Http\JsonResponse;
use stdClass;

/** The success answer of the v2 API: {data, meta}. */
final class Envelope
{
    /**
     * @param array<string, mixed> $meta
     * @param array<string, string> $headers
     */
    public static function success(mixed $data, array $meta = [], int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse(['data' => $data, 'meta' => $meta === [] ? new stdClass() : $meta], $status, $headers);
    }

    /** @param array<string, string> $headers */
    public static function empty(int $status = 204, array $headers = []): JsonResponse
    {
        return new JsonResponse(null, $status, $headers);
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function pagination(int $page, int $perPage, int $total, array $meta = []): array
    {
        return ['pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => max(1, (int) ceil($total / max(1, $perPage)))]] + $meta;
    }
}
