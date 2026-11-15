<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Downloads\MovieDownloadSearchData;
use App\Models\MovieDownloadSearch;
use Carbon\CarbonInterface;
use LogicException;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Persistence for on-demand download-link search state. */
final class MovieDownloadSearchRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(MovieDownloadSearch $model)
    {
        parent::__construct($model);
    }

    public function findForMovie(int $movieId): ?MovieDownloadSearchData
    {
        $row = $this->newQuery()->where('movie_id', $movieId)->first();

        return $row instanceof MovieDownloadSearch ? $this->toData($row) : null;
    }

    public function firstOrNewForMovie(int $movieId): MovieDownloadSearchData
    {
        $row = $this->findForMovie($movieId);
        if ($row instanceof MovieDownloadSearchData) {
            return $row;
        }

        return new MovieDownloadSearchData(
            id: null,
            movie_id: $movieId,
            state: MovieDownloadSearch::STATE_IDLE,
            found_count: 0,
        );
    }

    public function saveSearch(MovieDownloadSearchData $search): MovieDownloadSearchData
    {
        $model = $search->id === null
            ? new MovieDownloadSearch()
            : $this->newQuery()->findOrFail($search->id);
        if (! $model instanceof MovieDownloadSearch) {
            throw new LogicException('The download search repository returned an unexpected model.');
        }
        $model->fill([
            'movie_id' => $search->movie_id,
            'state' => $search->state,
            'found_count' => $search->found_count,
            'sources_queued' => $search->sources_queued,
            'sources_done' => $search->sources_done,
            'last_checked_at' => $search->last_checked_at,
            'cooldown_until' => $search->cooldown_until,
            'started_at' => $search->started_at,
            'last_error' => $search->last_error,
        ]);
        $model->save();

        return $this->toData($model);
    }

    private function toData(MovieDownloadSearch $search): MovieDownloadSearchData
    {
        return new MovieDownloadSearchData(
            id: (int) $search->id,
            movie_id: (int) $search->movie_id,
            state: (string) $search->state,
            found_count: (int) $search->found_count,
            sources_queued: array_map('strval', $search->sources_queued ?? []),
            sources_done: array_map('strval', $search->sources_done ?? []),
            last_checked_at: $this->dateString($search->last_checked_at),
            cooldown_until: $this->dateString($search->cooldown_until),
            started_at: $this->dateString($search->started_at),
            last_error: $search->last_error === null ? null : (string) $search->last_error,
        );
    }

    private function dateString(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : null;
    }
}
