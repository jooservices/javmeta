<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Source;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Database access used by crawl source services. */
final class SourceRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(Source $model)
    {
        parent::__construct($model);
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    public function enabledForDashboard(): \Illuminate\Support\Collection
    {
        return $this->newQuery()->toBase()
            ->where('enabled', true)
            ->orderBy('priority')
            ->get(['slug', 'name', 'last_success_at', 'last_error_at', 'last_error']);
    }

    /** @return list<string> */
    public function browserLaneSlugs(): array
    {
        return array_values(array_map('strval', $this->newQuery()->where('browser_lane', true)->pluck('slug')->all()));
    }

    /** @return list<object> */
    public function fetchMethodTotals(DateTimeInterface $since): array
    {
        return DB::table('crawl_fetch_attempts')
            ->where('created_at', '>=', $since)
            ->where('outcome', '!=', 'skipped')
            ->select('source_slug')
            ->selectRaw('COUNT(*) AS total, SUM(CASE WHEN method IN (?, ?) THEN 1 ELSE 0 END) AS browser', ['flaresolverr', 'playwright'])
            ->groupBy('source_slug')
            ->get()
            ->all();
    }

    /** @param list<string> $slugs */
    public function replaceBrowserLaneSlugs(array $slugs): void
    {
        DB::transaction(function () use ($slugs): void {
            $this->newQuery()->where('browser_lane', true)->update(['browser_lane' => false]);
            if ($slugs !== []) {
                $this->newQuery()->whereIn('slug', $slugs)->update(['browser_lane' => true]);
            }
        });
    }

    public function findSource(string $slug): ?Source
    {
        /** @var Source|null $source */
        $source = $this->newQuery()->find($slug);

        return $source;
    }

    public function isEnabled(string $slug): bool
    {
        return (bool) $this->newQuery()->whereKey($slug)->value('enabled');
    }

    /** @return list<string> */
    public function disabledSlugs(): array
    {
        return array_values(array_map('strval', $this->newQuery()->where('enabled', false)->pluck('slug')->all()));
    }

    /** @param array<string, mixed> $attributes */
    public function updateSource(string $slug, array $attributes): int
    {
        return $this->newQuery()->whereKey($slug)->update($attributes);
    }

    public function incrementRetryMetric(string $source, int $round, string $outcome, string $egressLabel): void
    {
        $key = [
            'source_slug' => $source,
            'round' => $round,
            'outcome' => $outcome,
            'egress_label' => $egressLabel,
        ];

        DB::table('crawl_retry_metrics')->insertOrIgnore($key + ['value' => 0]);
        DB::table('crawl_retry_metrics')->where($key)->increment('value');
    }

    /** @return list<array<string, mixed>> */
    public function statusRows(): array
    {
        /** @var Collection<int, Source> $sources */
        $sources = $this->newQuery()
            ->orderBy('priority')
            ->orderBy('slug')
            ->get([
                'slug',
                'name',
                'enabled',
                'gap_seconds_current',
                'gap_seconds_min',
                'gap_seconds_max',
                'last_success_at',
                'last_error_at',
                'last_error',
            ]);

        return $sources->map(static fn(Source $source): array => [
            'slug' => (string) $source->slug,
            'name' => (string) $source->name,
            'enabled' => (bool) $source->enabled,
            'gap_seconds_current' => (float) $source->gap_seconds_current,
            'gap_seconds_min' => (float) $source->gap_seconds_min,
            'gap_seconds_max' => (float) $source->gap_seconds_max,
            'last_success_at' => $source->getAttribute('last_success_at'),
            'last_error_at' => $source->getAttribute('last_error_at'),
            'last_error' => $source->last_error,
        ])->all();
    }
}
