<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2\Concerns;

use Illuminate\Support\Facades\Auth;

/** The id of the signed-in user of a v2 request, for the audit trail. */
trait KnowsTheActor
{
    private function actorId(): ?int
    {
        $id = Auth::guard('web')->id();

        return $id === null ? null : (int) $id;
    }
}
