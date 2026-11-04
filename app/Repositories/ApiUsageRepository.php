<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Auth\ApiKeyUsageSnapshot;
use App\Models\ApiUsageLog;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class ApiUsageRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(ApiUsageLog $model)
    {
        parent::__construct($model);
    }

    public function summaryForKey(int $id): ApiKeyUsageSnapshot
    {
        $stat = fn(int $hours): array => [
            'calls' => $this->newQuery()->where('api_key_id', $id)->where('created_at', '>=', now()->subHours($hours))->count(),
            'errors' => $this->newQuery()->where('api_key_id', $id)->where('created_at', '>=', now()->subHours($hours))->where('status_code', '>=', 400)->count(),
            'avg_ms' => (int) round((float) $this->newQuery()->where('api_key_id', $id)->where('created_at', '>=', now()->subHours($hours))->avg('response_ms')),
        ];
        /** @var \Illuminate\Database\Eloquent\Collection<int, ApiUsageLog> $records */
        $records = $this->newQuery()->where('api_key_id', $id)->where('created_at', '>=', now()->subHours(168))
            ->selectRaw('endpoint, COUNT(*) AS calls')
            ->groupBy('endpoint')
            ->orderByDesc('calls')
            ->limit(10)
            ->get();
        $top = $records->map(static fn(ApiUsageLog $log): array => ['endpoint' => $log->endpoint, 'calls' => (int) $log->getAttribute('calls')])->all();

        return new ApiKeyUsageSnapshot($stat(24), $stat(168), $top);
    }
}
