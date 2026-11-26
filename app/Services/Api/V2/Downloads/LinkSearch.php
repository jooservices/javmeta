<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use Illuminate\Container\Attributes\Bind;

/** Starts the search for download links of a movie. */
#[Bind(MovieLinkSearch::class)]
interface LinkSearch
{
    /** True when a new search was queued. */
    public function start(string $movieCode, string $clientKey): bool;
}
