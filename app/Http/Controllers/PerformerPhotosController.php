<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Api\PerformerPhotoPageItemDto;
use App\Exceptions\PerformerNotFoundException;
use App\Http\Requests\PerformerPhotosRequest;
use App\Services\Performers\PerformerPhotosService;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class PerformerPhotosController extends BaseApiController
{
    public function __invoke(PerformerPhotosRequest $request, PerformerPhotosService $photos, string $id): JsonResponse
    {
        $kind = (string) $request->validated('kind', 'gallery');
        $perPage = (int) $request->validated('per_page', 50);
        $page = (int) $request->validated('page', 1);
        $result = $photos->page($id, $kind, $perPage, $page);
        if ($result === null) {
            throw new PerformerNotFoundException($id);
        }
        $data = array_map(static fn(PerformerPhotoPageItemDto $media): array => $media->toArray(), $result['items']);

        return $this->respondWithData(
            data: $data,
            message: 'Performer photos retrieved successfully.',
            meta: [
                'total' => $result['total'],
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $result['per_page'],
                    'current_page' => $result['page'],
                    'last_page' => $result['last_page'],
                    'has_more' => $result['has_more'],
                ],
            ],
        );
    }
}
