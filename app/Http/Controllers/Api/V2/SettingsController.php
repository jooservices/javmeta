<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PutSettingsRequest;
use App\Services\Api\V2\Administration\Precondition;
use App\Services\Api\V2\Administration\SettingsAdministration;
use Illuminate\Http\JsonResponse;

final class SettingsController
{
    public function __construct(private readonly SettingsAdministration $settings) {}

    public function index(): JsonResponse
    {
        $groups = $this->settings->groups();

        return Envelope::success($groups, headers: ['ETag' => Precondition::etag($groups)]);
    }

    public function show(string $group): JsonResponse
    {
        $found = $this->settings->group($group);

        return Envelope::success($found, headers: ['ETag' => Precondition::etag($found)]);
    }

    public function update(PutSettingsRequest $request, string $group): JsonResponse
    {
        Precondition::assertMatches($request, $this->settings->group($group));
        $result = $this->settings->update(Precondition::actor($request), $group, (array) $request->input('values'));

        return Envelope::success($result, headers: ['ETag' => Precondition::etag($this->settings->group($group))]);
    }
}
