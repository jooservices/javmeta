<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\CreateApiKeyRequest;
use App\Models\ApiKey;
use App\Models\ApiUsageLog;
use App\Observability\ObservabilityEmitter;
use App\Services\Auth\ApiKeyService;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use JOOservices\LaravelController\Http\Controllers\BaseApiController;

final class ApiKeyController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $keys = ApiKey::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn(ApiKey $apiKey): array => $this->serializeKey($apiKey))
            ->values()
            ->all();

        return $this->respondWithData($keys);
    }

    public function store(CreateApiKeyRequest $request, ApiKeyService $apiKeyService, ObservabilityEmitter $observability): JsonResponse
    {
        $validated = $request->validated();
        $created = $apiKeyService->create(
            (string) $validated['label'],
            (int) ($validated['abuse_rpm'] ?? 60),
            isset($validated['scopes']) ? array_values(array_unique($validated['scopes'])) : null,
        );

        $observability->emitOps('admin_api_key', [
            'action' => 'create',
            'api_key_id' => $created->model->getKey(),
        ]);

        return $this->created([
            ...$this->serializeKey($created->model),
            'plaintext' => $created->plaintext,
        ]);
    }

    /** Calls, errors and response times of one key over the last 24 hours and 7 days, and its busiest endpoints. */
    public function usage(int $id): JsonResponse
    {
        ApiKey::query()->findOrFail($id);
        $since = fn(int $hours) => now()->subHours($hours);
        $stat = fn(int $hours): array => [
            'calls' => ApiUsageLog::query()->where('api_key_id', $id)->where('created_at', '>=', $since($hours))->count(),
            'errors' => ApiUsageLog::query()->where('api_key_id', $id)->where('created_at', '>=', $since($hours))->where('status_code', '>=', 400)->count(),
            'avg_ms' => (int) round((float) ApiUsageLog::query()->where('api_key_id', $id)->where('created_at', '>=', $since($hours))->avg('response_ms')),
        ];
        $top = ApiUsageLog::query()->where('api_key_id', $id)->where('created_at', '>=', $since(168))
            ->selectRaw('endpoint, COUNT(*) AS calls')->groupBy('endpoint')->orderByDesc('calls')->limit(10)->get()
            ->map(static fn($r): array => ['endpoint' => $r->endpoint, 'calls' => (int) $r->getAttribute('calls')])->all();

        return $this->respondWithData(['last_24h' => $stat(24), 'last_7d' => $stat(168), 'top_endpoints' => $top]);
    }

    public function destroy(int $id, ApiKeyService $apiKeyService, ObservabilityEmitter $observability): JsonResponse
    {
        $apiKeyService->revoke($id);
        $observability->emitOps('admin_api_key', [
            'action' => 'revoke',
            'api_key_id' => $id,
        ]);

        return $this->respondNoContent();
    }

    /** @return array<string, mixed> */
    private function serializeKey(ApiKey $apiKey): array
    {
        return [
            'id' => $apiKey->getKey(),
            'prefix' => $apiKey->prefix,
            'label' => $apiKey->label,
            'status' => $apiKey->status,
            'revoked_at' => $this->formatDate($apiKey->getAttribute('revoked_at')),
            'abuse_rpm' => $apiKey->abuse_rpm,
            'scopes' => $apiKey->scopes,
            'created_at' => $this->formatDate($apiKey->getAttribute('created_at')),
        ];
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->toISOString() : null;
    }
}
