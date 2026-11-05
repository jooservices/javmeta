<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Http\Resources\MovieSummaryResource;
use App\Models\Movie;
use DateTimeInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class UiMarksMovieRepository extends EloquentRepository implements ReadRepositoryInterface
{
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Movie $model)
    {
        parent::__construct($model);
    }

    /** @param list<int> $performerIds @param list<string> $makers @param list<string> $series @return list<string> */
    public function feedCodes(DateTimeInterface $since, array $performerIds, array $makers, array $series): array
    {
        $query = $this->newQuery()->toBase()->where('first_seen_at', '>=', $since)->whereNull('delisted_at');
        $query->where(static function (Builder $where) use ($performerIds, $makers, $series): void {
            if ($performerIds !== []) {
                $where->orWhereIn('id', DB::table('movie_performers')->whereIn('performer_id', $performerIds)->select('movie_id'));
            }
            if ($makers !== []) {
                $where->orWhereIn('maker', $makers);
            }
            if ($series !== []) {
                $where->orWhereIn('series', $series);
            }
        });

        /** @var list<string> $codes */
        $codes = $query->orderByDesc('first_seen_at')->limit(60)->pluck('display_code')->all();

        return $codes;
    }

    /** @param list<string> $codes @return list<array<string, mixed>> */
    public function summaries(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $movies = $this->newQuery()->with(['codes', 'performers.aliases', 'performers.sources', 'genres', 'media', 'credits'])
            ->whereIn('display_code', $codes)->get()->keyBy('display_code');
        /** @var list<Movie> $ordered */
        $ordered = [];
        foreach ($codes as $code) {
            $movie = $movies->get($code);
            if ($movie instanceof Movie) {
                $ordered[] = $movie;
            }
        }

        /** @var list<array<string, mixed>> $summaries */
        $summaries = MovieSummaryResource::collection(collect($ordered))->resolve(Request::create('/'));

        return $summaries;
    }

    /** @param list<string> $codes */
    public function countFirstSeenAfter(array $codes, string $lastVisit): int
    {
        return $this->newQuery()->toBase()
            ->whereIn('display_code', $codes)
            ->where('first_seen_at', '>', $lastVisit)
            ->count();
    }
}
