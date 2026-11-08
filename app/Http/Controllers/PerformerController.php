<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\PerformerNotFoundException;
use App\Exceptions\InvalidFilterException;
use App\Http\Requests\PerformerSearchRequest;
use App\Http\Resources\PerformerResource;
use App\Http\Resources\PerformerSummaryResource;
use App\Models\Performer;
use App\Repositories\PerformerRepository;
use App\Services\Miss\ConsumerMissReporter;
use App\Services\Search\CursorCodec;
use Illuminate\Http\JsonResponse;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class PerformerController extends BaseApiController
{
    public function index(PerformerSearchRequest $request, PerformerRepository $performers, CursorCodec $codec): JsonResponse
    {
        $perPage = (int) $request->validated('per_page', 15);
        $sort = (string) $request->validated('sort', 'name');
        $filters = $request->validated();
        unset($filters['sort'], $filters['cursor'], $filters['page'], $filters['per_page']);

        $cursorParam = $request->validated('cursor');
        $cursor = is_string($cursorParam) && $cursorParam !== ''
            ? $codec->decode($cursorParam, PerformerRepository::SORTS)
            : null;

        if ($cursor !== null) {
            $explicitSort = $request->validated('sort');
            if (is_string($explicitSort) && $explicitSort !== $cursor['sort']) {
                throw new InvalidFilterException('The sort parameter cannot change between pages.');
            }
        }

        if ($cursor === null && $request->has('page')) {
            $repository = $performers->withDisplayRelations()->filter($filters)->orderForList($sort);

            return $this->respondWithPagination(
                paginator: $repository->paginate($perPage),
                resourceClass: PerformerSummaryResource::class,
                message: 'Performers retrieved successfully.',
            );
        }

        $result = $performers->search($filters, $cursor['sort'] ?? $sort, $cursor, $perPage);
        $nextCursor = $result['last_id'] === null
            ? null
            : $codec->encode($result['sort'], $result['last_sort'], $result['last_id'], PerformerRepository::SORTS);

        return $this->respondWithData(
            data: PerformerSummaryResource::collection($result['items']),
            message: 'Performers retrieved successfully.',
            meta: [
                'pagination' => [
                    'total' => $result['total'],
                    'per_page' => $perPage,
                    'sort' => $result['sort'],
                    'has_more' => $result['has_more'],
                    'cursor' => $cursorParam,
                    'next_cursor' => $nextCursor,
                    'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
                ],
            ],
        );
    }

    public function show(PerformerRepository $performers, ConsumerMissReporter $misses, string $id): JsonResponse
    {
        $performer = $performers->findForDisplay($id);

        if (! $performer instanceof Performer) {
            $misses->report('performer', (string) $id, ['endpoint' => 'performers.show']);

            throw new PerformerNotFoundException($id);
        }

        return $this->respondWithResource(
            resource: new PerformerResource($performer),
            message: 'Performer retrieved successfully.',
        );
    }
}
