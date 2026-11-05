<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Auth\ApiKeyList;
use App\Data\Auth\ApiKeySnapshot;
use App\Data\Auth\CreatedApiKeySnapshot;
use App\Models\ApiKey;
use App\Support\Auth\CreatedApiKey;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class ApiKeyAdministrationRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(ApiKey $model)
    {
        parent::__construct($model);
    }

    public function allForAdministration(): ApiKeyList
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, ApiKey> $records */
        $records = $this->newQuery()->orderByDesc('id')->get();
        /** @var list<ApiKeySnapshot> $keys */
        $keys = $records->map(fn(ApiKey $key): ApiKeySnapshot => $this->snapshot($key))
            ->all();

        return new ApiKeyList($keys);
    }

    public function assertExists(int $id): void
    {
        $this->newQuery()->findOrFail($id);
    }

    public function created(CreatedApiKey $created): CreatedApiKeySnapshot
    {
        return new CreatedApiKeySnapshot($this->snapshot($created->model), $created->plaintext);
    }

    private function snapshot(ApiKey $key): ApiKeySnapshot
    {
        /** @var list<string>|null $scopes */
        $scopes = $key->scopes;

        return new ApiKeySnapshot(
            (int) $key->getKey(),
            (string) $key->prefix,
            (string) $key->label,
            (string) $key->status,
            $this->formatDate($key->getAttribute('revoked_at')),
            (int) $key->abuse_rpm,
            $scopes,
            $this->formatDate($key->getAttribute('created_at')),
        );
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? Carbon::instance($value)->toISOString() : null;
    }
}
