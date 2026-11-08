<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlEvent;
use DateTimeInterface;
use Illuminate\Support\Collection;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class CrawlEventRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlEvent $model)
    {
        parent::__construct($model);
    }

    /** @return Collection<int, \stdClass> */
    public function recent(int $limit): Collection
    {
        return $this->newQuery()->toBase()
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['source_slug', 'kind', 'created_at']);
    }

    /** @param array<string, mixed> $detail */
    public function record(string $sourceSlug, string $kind, array $detail = [], ?string $url = null, ?DateTimeInterface $createdAt = null): void
    {
        $this->newQuery()->create([
            'source_slug' => $sourceSlug,
            'kind' => $kind,
            'url' => $url,
            'detail' => $detail,
            'created_at' => $createdAt ?? now(),
        ]);
    }
}
