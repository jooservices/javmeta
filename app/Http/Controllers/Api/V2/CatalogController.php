<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\CatalogPageRequest;
use App\Http\Requests\Api\V2\PhotoCollectionRequest;
use App\Services\Api\V2\Catalogue\CatalogEntries;
use App\Services\Api\V2\Catalogue\PhotoCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CatalogController
{
    public function __construct(private readonly CatalogEntries $catalogs, private readonly PhotoCollection $photos) {}

    public function kinds(): JsonResponse
    {
        return Envelope::success($this->catalogs->kinds());
    }

    public function entries(CatalogPageRequest $request, string $kind): JsonResponse
    {
        $result = $this->catalogs->page($kind, $request->query());

        return Envelope::success($result['data'], $result['meta']);
    }

    public function index(Request $request, string $kind): JsonResponse
    {
        return Envelope::success($this->catalogs->index($kind, $request->query()));
    }

    public function entry(string $kind, string $slug): JsonResponse
    {
        return Envelope::success($this->catalogs->entry($kind, $slug));
    }

    public function photos(PhotoCollectionRequest $request): JsonResponse
    {
        $result = $this->photos->page($request->query());

        return Envelope::success($result['data'], $result['meta']);
    }
}
