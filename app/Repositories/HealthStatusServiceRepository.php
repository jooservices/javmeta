<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\CrawlQueue;
use App\Models\Source;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Read-only persistence queries for health status and readiness checks. */
final class HealthStatusServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(CrawlQueue $model)
    {
        parent::__construct($model);
    }

    /** @return array{pending: int, claimed: int, failed: int} */
    public function queueCounts(): array
    {
        return [
            'pending' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_PENDING)->count(),
            'claimed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_CLAIMED)->count(),
            'failed' => CrawlQueue::query()->where('status', CrawlQueue::STATUS_FAILED)->count(),
        ];
    }

    /** @return list<array{slug: string, last_success_at: string|null, last_error_at: string|null}> */
    public function sourceRows(): array
    {
        return Source::query()->orderBy('priority')->orderBy('slug')->get(['slug', 'last_success_at', 'last_error_at'])
            ->map(static function (Source $source): array {
                $lastSuccess = $source->getAttribute('last_success_at');
                $lastError = $source->getAttribute('last_error_at');

                return [
                    'slug' => (string) $source->getAttribute('slug'),
                    'last_success_at' => $lastSuccess instanceof DateTimeInterface ? $lastSuccess->format(DATE_ATOM) : null,
                    'last_error_at' => $lastError instanceof DateTimeInterface ? $lastError->format(DATE_ATOM) : null,
                ];
            })->all();
    }

    public function newestMovieAt(): ?string
    {
        $value = DB::table('movies')->max('first_seen_at');

        return $value === null ? null : (string) $value;
    }
}
