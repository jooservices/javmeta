<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use Illuminate\Container\Attributes\Bind;

/** Signed links to files that live in a served folder. */
#[Bind(ServedFileLinks::class)]
interface FileLinks
{
    /** The link of a file, or null when file serving is off or the file is not in a served folder. */
    public function linkFor(string $path): ?string;
}
