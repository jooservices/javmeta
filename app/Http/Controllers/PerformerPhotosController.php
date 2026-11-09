<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Api\PhotoDto;
use App\Exceptions\PerformerNotFoundException;
use App\Http\Requests\PerformerPhotosRequest;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Repositories\PerformerRepository;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class PerformerPhotosController extends BaseApiController
{
    public function __invoke(PerformerPhotosRequest $request, PerformerRepository $performers, string $id): JsonResponse
    {
        $performer = $performers->findForDisplay($id);
        if (! $performer instanceof Performer) {
            throw new PerformerNotFoundException($id);
        }

        $perPage = (int) $request->validated('per_page', 50);
        $page = (int) $request->validated('page', 1);
        $result = $performers->galleryPage((int) $performer->id, $perPage, $page);
        $data = $result['items']->map(static function (PerformerMedia $media): array {
            $meta = $media->getAttribute('meta');
            $crawledAt = $media->getAttribute('crawled_at');

            return (new PhotoDto(
                url: (string) $media->getAttribute('url'),
                thumbnailUrl: is_array($meta) && is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : null,
                crawledAt: $crawledAt instanceof DateTimeInterface ? $crawledAt->format(DATE_ATOM) : (is_string($crawledAt) ? $crawledAt : null),
                source: (string) ($media->source_slug ?: 'unknown'),
            ))->toArray();
        })->all();

        return $this->respondWithData(
            data: $data,
            message: 'Performer photos retrieved successfully.',
            meta: [
                'total' => $result['total'],
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $result['per_page'],
                    'current_page' => $result['page'],
                    'has_more' => $result['has_more'],
                ],
            ],
        );
    }
}
