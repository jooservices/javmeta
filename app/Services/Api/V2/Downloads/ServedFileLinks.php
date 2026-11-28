<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Services\Downloads\FileLinkService;

/** The file server of the owner's settings seen through the narrow interface the API needs. */
final class ServedFileLinks implements FileLinks
{
    public function __construct(private readonly FileLinkService $files) {}

    public function linkFor(string $path): ?string
    {
        return $this->files->enabled() && $this->files->resolve($path) !== null ? $this->files->url($path) : null;
    }
}
