<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\ReviewFlag;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Database access for source URL failure review flags. */
final class CrawlTitleFailureServiceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(ReviewFlag $model)
    {
        parent::__construct($model);
    }

    /** @return array{id: int, display_code: string|null, code_normalized: string|null, delisted_at: mixed}|null */
    public function movieForSourceUrl(string $sourceSlug, string $url): ?array
    {
        $movie = DB::table('movie_sources')
            ->join('movies', 'movies.id', '=', 'movie_sources.movie_id')
            ->where('movie_sources.site_slug', $sourceSlug)
            ->where('movie_sources.source_url', $url)
            ->first(['movies.id', 'movies.display_code', 'movies.code_normalized', 'movies.delisted_at']);

        return $movie === null ? null : [
            'id' => (int) $movie->id,
            'display_code' => $movie->display_code,
            'code_normalized' => $movie->code_normalized,
            'delisted_at' => $movie->delisted_at,
        ];
    }

    public function markDelisted(int $movieId, mixed $at): void
    {
        DB::table('movies')->where('id', $movieId)->update(['delisted_at' => $at, 'updated_at' => $at]);
    }

    /** @return array{id: int, consecutive_failures: int, movie_id: int|null, code_normalized: string|null}|null */
    public function activeFailureFlag(string $sourceSlug, ?int $movieId, string $urlKey): ?array
    {
        $flag = $this->newQuery()
            ->where('source_slug', $sourceSlug)
            ->whereNull('resolved_at')
            ->where('reason', 'consecutive_crawl_failure')
            ->when(
                $movieId !== null,
                static fn($query) => $query->where('movie_id', $movieId),
                static fn($query) => $query->whereNull('movie_id')->where('code_normalized', $urlKey),
            )
            ->first(['id', 'consecutive_failures', 'movie_id', 'code_normalized']);

        return $flag === null ? null : [
            'id' => (int) $flag->getAttribute('id'),
            'consecutive_failures' => (int) $flag->getAttribute('consecutive_failures'),
            'movie_id' => $flag->getAttribute('movie_id') === null ? null : (int) $flag->getAttribute('movie_id'),
            'code_normalized' => $flag->getAttribute('code_normalized'),
        ];
    }

    /** @param array<string, mixed> $attributes */
    public function updateFailureFlag(int $id, array $attributes): void
    {
        $this->newQuery()->whereKey($id)->update($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public function createFailureFlag(array $attributes): void
    {
        $this->newQuery()->create($attributes);
    }

    public function resolveFailureFlags(string $sourceSlug, ?int $movieId, string $urlKey, mixed $at): void
    {
        $this->newQuery()
            ->where('source_slug', $sourceSlug)
            ->whereNull('resolved_at')
            ->where('reason', 'consecutive_crawl_failure')
            ->when(
                $movieId !== null,
                static fn($query) => $query->where('movie_id', $movieId),
                static fn($query) => $query->whereNull('movie_id')->where('code_normalized', $urlKey),
            )
            ->update(['resolved_at' => $at]);
    }

    public function markNeedsReview(int $movieId, mixed $at): void
    {
        DB::table('movies')->where('id', $movieId)->update(['needs_review' => true, 'updated_at' => $at]);
    }
}
