<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\Paging;
use App\Http\Requests\Api\V2\InterpretationRequest;
use App\Http\Requests\Api\V2\SearchRequest;
use App\Services\Api\V2\Discovery\InterpretationService;
use App\Services\Api\V2\Discovery\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

final class SearchController
{
    public function __construct(private readonly SearchService $search, private readonly InterpretationService $interpretations) {}

    public function index(SearchRequest $request): JsonResponse
    {
        [$page, $perPage] = Paging::read($request->query(), 12, 48);
        $result = $this->search->search((string) $request->input('q'), (string) $request->query('types', ''), $page, $perPage);

        return Envelope::success($result['data'], $result['meta']);
    }

    public function suggestions(Request $request): JsonResponse
    {
        return Envelope::success($this->search->suggestions(trim((string) $request->query('q', '')), (int) $request->query('limit', 6) ?: 6));
    }

    public function interpretations(InterpretationRequest $request): JsonResponse
    {
        $text = (string) $request->input('q');
        $ignore = array_map('strval', (array) $request->input('ignore', []));
        $reading = $request->input('target') === 'performers' ? $this->interpretations->performers($text, $ignore) : $this->interpretations->movies($text, $ignore);

        return Envelope::success(['query' => $text, ...$reading, 'filters' => $reading['filters'] === [] ? new stdClass() : $reading['filters']]);
    }

    public function photos(Request $request): JsonResponse
    {
        [$page, $perPage] = Paging::read($request->query(), 48, 120);
        $result = $this->search->photos($request->query(), $page, $perPage);

        return Envelope::success($result['data'], $result['meta']);
    }

    public function catalogue(Request $request): JsonResponse
    {
        return Envelope::success($this->search->catalogue((string) $request->query('q', '')));
    }
}
