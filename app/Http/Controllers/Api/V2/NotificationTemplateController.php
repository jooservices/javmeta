<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Http\Api\V2\Envelope;
use App\Http\Requests\Api\V2\PreviewTemplateRequest;
use App\Http\Requests\Api\V2\PutTemplateRequest;
use App\Services\Api\V2\Administration\Precondition;
use App\Services\Api\V2\Administration\TemplateAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NotificationTemplateController
{
    public function __construct(private readonly TemplateAdministration $templates) {}

    public function index(): JsonResponse
    {
        return Envelope::success($this->templates->all());
    }

    public function show(string $key): JsonResponse
    {
        $template = $this->templates->one($key);

        return Envelope::success($template, headers: ['ETag' => Precondition::etag($template)]);
    }

    public function update(PutTemplateRequest $request, string $key): JsonResponse
    {
        Precondition::assertMatches($request, $this->templates->one($key));
        $changes = $request->validated();
        $template = $this->templates->update(Precondition::actor($request), Precondition::actorId($request), $key, $changes);

        return Envelope::success($template, headers: ['ETag' => Precondition::etag($template)]);
    }

    public function revisions(string $key): JsonResponse
    {
        return Envelope::success($this->templates->revisions($key));
    }

    public function restore(Request $request, string $key, int $rev): JsonResponse
    {
        $template = $this->templates->restore(Precondition::actor($request), Precondition::actorId($request), $key, $rev);

        return Envelope::success($template, status: 201, headers: ['Location' => "/api/v2/notification-templates/{$key}"]);
    }

    public function preview(PreviewTemplateRequest $request, string $key): JsonResponse
    {
        return Envelope::success($this->templates->preview($key, $request->validated()));
    }

    public function testDelivery(Request $request, string $key): JsonResponse
    {
        return Envelope::success($this->templates->testDelivery(Precondition::actor($request), $key), status: 202);
    }

    public function reset(Request $request, string $key): JsonResponse
    {
        $this->templates->reset(Precondition::actor($request), Precondition::actorId($request), $key);

        return Envelope::empty();
    }
}
