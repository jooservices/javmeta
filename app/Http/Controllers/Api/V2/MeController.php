<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Caller;
use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\UpdateMeRequest;
use App\Http\Requests\Api\V2\UpdatePreferencesRequest;
use App\Services\Api\V2\Me\Preferences;
use App\Services\Api\V2\Me\Profile;
use Illuminate\Http\JsonResponse;
use stdClass;

final class MeController
{
    public function __construct(private readonly Profile $profile, private readonly Preferences $preferences) {}

    public function show(): JsonResponse
    {
        return Envelope::success($this->profile->show(Caller::userId()));
    }

    public function update(UpdateMeRequest $request): JsonResponse
    {
        $name = $request->has('name') ? (string) $request->input('name') : null;
        $new = $request->has('new_password') ? (string) $request->input('new_password') : null;

        $current = $request->input('current_password');

        return Envelope::success($this->profile->update(Caller::userId(), $name, is_string($current) ? $current : null, $new)->toArray());
    }

    public function preferences(UpdatePreferencesRequest $request): JsonResponse
    {
        $all = $this->preferences->change(Caller::userId(), $request->validated());

        return Envelope::success($all === [] ? new stdClass() : $all);
    }
}
