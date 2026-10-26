<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\Performer;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use JOOservices\LaravelRepository\Contracts\RepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

final class PerformerRepository extends EloquentRepository implements RepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;
    /** @var list<string> */
    public const SORTS = ['name', 'updated_at', 'linked_title_count'];

    public function __construct(Performer $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  iterable<string, mixed>  $filters
     */
    public function filter(iterable $filters): static
    {
        $this->getQuery()->whereNull('canonical_performer_id');
        $this->applyFilters($this->getQuery(), is_array($filters) ? $filters : iterator_to_array($filters));

        return $this;
    }

    /**
     * Accurate total for a filter set without hydrating items.
     *
     * @param  array<string, mixed>  $filters
     */
    public function countFiltered(array $filters): int
    {
        $query = $this->newQuery()->whereNull('canonical_performer_id');
        $this->applyFilters($query, $filters);

        return $query->count();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{sort: string, last_sort: int|float|string|null, last_id: int}|null  $cursor
     * @return array{items: Collection<int, Performer>, total: int, sort: string, last_sort: int|float|string|null, last_id: int|null, has_more: bool}
     */
    public function search(array $filters, string $sort, ?array $cursor, int $perPage): array
    {
        $query = $this->newQuery()
            ->whereNull('canonical_performer_id')
            ->with('aliases')
            ->with('sources');
        // Counts affect ordering only for this sort; other lists count the selected page in one indexed batch.
        if ($sort === 'linked_title_count') {
            // A derived column is visible to WHERE on PostgreSQL; a SELECT alias is not.
            $query->fromSub(Performer::query()->withCount('movies')->toBase(), 'performers');
        }
        $this->applyFilters($query, $filters);

        $total = (clone $query)->count();
        $this->applySort($query, $sort);

        if ($cursor !== null) {
            $this->applyCursor($query, $cursor['sort'], $cursor['last_sort'], $cursor['last_id']);
        }

        /** @var Collection<int, Performer> $page */
        $page = $query->limit($perPage + 1)->get();
        $hasMore = $page->count() > $perPage;
        $items = $hasMore ? $page->slice(0, $perPage)->values() : $page;
        if ($sort !== 'linked_title_count' && $items->isNotEmpty()) {
            $counts = DB::table('movie_performers')->whereIn('performer_id', $items->modelKeys())
                ->selectRaw('performer_id, COUNT(*) AS total')->groupBy('performer_id')->pluck('total', 'performer_id');
            foreach ($items as $performer) {
                $performer->setAttribute('movies_count', (int) ($counts[$performer->id] ?? 0));
            }
        }
        $last = $items->last();

        return [
            'items' => $items,
            'total' => $total,
            'sort' => $sort,
            'last_sort' => $last instanceof Performer ? $this->sortValue($last, $sort) : null,
            'last_id' => $last instanceof Performer ? (int) $last->id : null,
            'has_more' => $hasMore,
        ];
    }

    public function orderForList(string $sort = 'name'): static
    {
        $this->applySort($this->getQuery(), $sort);

        return $this;
    }

    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $filters
     *
     * @SuppressWarnings("PHPMD.ExcessiveMethodLength")
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $like = $this->likeOperator();
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : '';

        if ($q !== '') {
            $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
            if (DB::getDriverName() === 'pgsql') {
                // Each branch can use its own trigram index; one OR over a sub-select and a long text cannot.
                $query->whereIn('performers.id', static function ($ids) use ($like, $pattern): void {
                    $ids->select('id')->from('performers')->where('name_romaji', $like, $pattern)
                        ->union(DB::table('performers')->select('id')->where('name_kanji', $like, $pattern))
                        ->union(DB::table('performers')->select('id')->where('name_kana', $like, $pattern))
                        ->union(DB::table('performers')->select('id')->where('bio_text', $like, $pattern))
                        ->union(DB::table('performer_aliases')->select('performer_id')->where('alias', $like, $pattern))
                        ->union(DB::table('performer_sources')->select('performer_id')->where('name_romaji', $like, $pattern)->orWhere('name_kanji', $like, $pattern)->orWhere('name_kana', $like, $pattern));
                });
            } else {
                $query->where(static function (Builder $performers) use ($like, $pattern): void {
                    $performers->where('name_romaji', $like, $pattern)
                        ->orWhere('name_kanji', $like, $pattern)
                        ->orWhere('name_kana', $like, $pattern)
                        ->orWhere('bio_text', $like, $pattern)
                        ->orWhereHas('aliases', static function (Builder $aliases) use ($like, $pattern): void {
                            $aliases->where('alias', $like, $pattern);
                        })
                        ->orWhereHas('sources', static function (Builder $sources) use ($like, $pattern): void {
                            $sources->where(static function (Builder $sourceNames) use ($like, $pattern): void {
                                $sourceNames->where('name_romaji', $like, $pattern)
                                    ->orWhere('name_kanji', $like, $pattern)
                                    ->orWhere('name_kana', $like, $pattern);
                            });
                        });
                });
            }
        }

        foreach ([
            'height' => 'height_cm',
            'bust' => 'bust',
            'waist' => 'waist',
            'hip' => 'hip',
        ] as $prefix => $column) {
            $minimum = $filters[$prefix . '_min'] ?? null;
            if (is_numeric($minimum)) {
                $query->where($column, '>=', (int) $minimum);
            }

            $maximum = $filters[$prefix . '_max'] ?? null;
            if (is_numeric($maximum)) {
                $query->where($column, '<=', (int) $maximum);
            }
        }

        $ageMin = $filters['age_min'] ?? null;
        if (is_numeric($ageMin)) {
            $query->whereDate('birth_date', '<=', today()->subYears((int) $ageMin)->toDateString());
        }

        $ageMax = $filters['age_max'] ?? null;
        if (is_numeric($ageMax)) {
            $query->whereDate('birth_date', '>', today()->subYears((int) $ageMax + 1)->toDateString());
        }

        // Several values separated by commas mean any of them (cups B and C, blood types A or O).
        foreach (['cup', 'blood_type', 'location'] as $column) {
            $values = array_values(array_filter(array_map('trim', explode(',', (string) ($filters[$column] ?? ''))), static fn(string $v): bool => $v !== ''));
            if ($values !== []) {
                $query->where(static function (Builder $any) use ($values, $column, $like): void {
                    foreach ($values as $value) {
                        $any->orWhere($column, $like, '%' . $value . '%');
                    }
                });
            }
        }

        if (isset($filters['has_image'])) {
            (string) $filters['has_image'] === '1'
                ? $query->whereNotNull('image_url')->where('image_url', '<>', '')
                : $query->where(static fn(Builder $w) => $w->whereNull('image_url')->orWhere('image_url', ''));
        }
        if (isset($filters['has_bio'])) {
            (string) $filters['has_bio'] === '1'
                ? $query->whereNotNull('bio_text')->where('bio_text', '<>', '')
                : $query->where(static fn(Builder $w) => $w->whereNull('bio_text')->orWhere('bio_text', ''));
        }
        if (isset($filters['status'])) {
            $filters['status'] === 'retired' ? $query->whereNotNull('retired_date') : $query->whereNull('retired_date');
        }
        if (is_numeric($filters['movies_min'] ?? null)) {
            $query->whereRaw('(select count(*) from movie_performers mp where mp.performer_id = performers.id) >= ?', [(int) $filters['movies_min']]);
        }
        $year = DB::getDriverName() === 'sqlite' ? "CAST(strftime('%Y', debut_date) AS INTEGER)" : 'EXTRACT(YEAR FROM debut_date)';
        if (is_numeric($filters['debut_from'] ?? null)) {
            $query->whereRaw($year . ' >= ?', [(int) $filters['debut_from']]);
        }
        if (is_numeric($filters['debut_to'] ?? null)) {
            $query->whereRaw($year . ' <= ?', [(int) $filters['debut_to']]);
        }
        if (is_string($filters['source'] ?? null) && trim($filters['source']) !== '') {
            $slugs = array_values(array_filter(array_map('trim', explode(',', $filters['source']))));
            $query->whereIn('performers.id', DB::table('performer_sources')->select('performer_id')->whereIn('site_slug', $slugs));
        }
    }

    /**
     * What the performers of a filter have in common, with counts, to narrow the search one click at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function facets(array $filters): array
    {
        $query = $this->newQuery()->whereNull('canonical_performer_id');
        $this->applyFilters($query, $filters);
        $ids = $query->toBase()->select('performers.id');
        $count = static fn($rows): array => $rows->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all();
        $by = static fn(string $column) => $count(DB::table('performers')->whereIn('id', clone $ids)->whereNotNull($column)->where($column, '<>', '')->selectRaw($column . ' AS name, COUNT(*) AS c')->groupBy($column)->orderByDesc('c')->orderBy($column)->limit(15)->get());
        $decade = DB::getDriverName() === 'sqlite' ? "(CAST(strftime('%Y', birth_date) AS INTEGER) / 10) * 10" : '(EXTRACT(YEAR FROM birth_date)::int / 10) * 10';

        return [
            'total' => (int) DB::table('performers')->whereIn('id', clone $ids)->count(),
            'cups' => $by('cup'),
            'blood_types' => $by('blood_type'),
            'locations' => $by('location'),
            'birth_decades' => $count(DB::table('performers')->whereIn('id', clone $ids)->whereNotNull('birth_date')->selectRaw($decade . ' AS name, COUNT(*) AS c')->groupByRaw($decade)->orderByDesc('name')->limit(10)->get()),
            'sources' => $count(DB::table('performer_sources')->whereIn('performer_id', clone $ids)->selectRaw('site_slug AS name, COUNT(DISTINCT performer_id) AS c')->groupBy('site_slug')->orderByDesc('c')->limit(15)->get()),
            'status' => [
                ['name' => 'active', 'count' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNull('retired_date')->count()],
                ['name' => 'retired', 'count' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNotNull('retired_date')->count()],
            ],
            'with_image' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNotNull('image_url')->where('image_url', '<>', '')->count(),
            'with_bio' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNotNull('bio_text')->where('bio_text', '<>', '')->count(),
        ];
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySort(Builder $query, string $sort): void
    {
        if (! in_array($sort, self::SORTS, true)) {
            $sort = 'name';
        }

        match ($sort) {
            'updated_at' => $query->orderByDesc('updated_at')->orderByDesc('id'),
            'linked_title_count' => $query->orderByDesc('movies_count')->orderByDesc('id'),
            default => $query->orderByRaw("COALESCE(name_romaji, name_kanji, name_kana, '') ASC, id ASC"),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyCursor(Builder $query, string $sort, int|float|string|null $lastSort, int $lastId): void
    {
        if ($sort === 'updated_at') {
            $query->where(static function (Builder $next) use ($lastSort, $lastId): void {
                $next->where('updated_at', '<', $lastSort)
                    ->orWhere(static function (Builder $tie) use ($lastSort, $lastId): void {
                        $tie->where('updated_at', '=', $lastSort)->where('id', '<', $lastId);
                    });
            });

            return;
        }

        if ($sort === 'linked_title_count') {
            $query->where(static function (Builder $next) use ($lastSort, $lastId): void {
                $next->where('movies_count', '<', (int) $lastSort)
                    ->orWhere(static function (Builder $tie) use ($lastSort, $lastId): void {
                        $tie->where('movies_count', '=', (int) $lastSort)->where('id', '<', $lastId);
                    });
            });

            return;
        }

        $query->where(static function (Builder $next) use ($lastSort, $lastId): void {
            $nameExpression = "COALESCE(name_romaji, name_kanji, name_kana, '')";
            $next->whereRaw("{$nameExpression} > ?", [$lastSort])
                ->orWhere(static function (Builder $tie) use ($nameExpression, $lastSort, $lastId): void {
                    $tie->whereRaw("{$nameExpression} = ?", [$lastSort])->where('id', '>', $lastId);
                });
        });
    }

    private function sortValue(Performer $performer, string $sort): int|string|null
    {
        return match ($sort) {
            'updated_at' => $this->formatDateTime($performer->getAttribute('updated_at')),
            'linked_title_count' => (int) $performer->getAttribute('movies_count'),
            default => (string) ($performer->name_romaji ?? $performer->name_kanji ?? $performer->name_kana ?? ''),
        };
    }

    private function formatDateTime(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : null;
    }

    private function likeOperator(): string
    {
        return DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Eager-load aliases for performer list/summary responses (avoids N+1).
     * Detail loads linked_title_count separately via findForDisplay().
     */
    public function withDisplayRelations(): static
    {
        $this->getQuery()->whereNull('canonical_performer_id')->with(['aliases', 'sources'])->withCount('movies');

        return $this;
    }

    public function findForDisplay(string|int $id): ?Performer
    {
        $query = $this->newQuery()->whereNull('canonical_performer_id')
            ->with(['aliases', 'media', 'sources'])->withCount('movies');
        $performer = ctype_digit((string) $id)
            ? $query->find((int) $id)
            : $query->where('uuid', (string) $id)->first();

        if (! $performer instanceof Performer) {
            $canonicalId = ctype_digit((string) $id)
                ? Performer::query()->whereKey((int) $id)->value('canonical_performer_id')
                : Performer::query()->where('uuid', (string) $id)->value('canonical_performer_id');
            if (is_numeric($canonicalId)) {
                $performer = $this->newQuery()->whereNull('canonical_performer_id')
                    ->with(['aliases', 'media', 'sources'])->withCount('movies')->find((int) $canonicalId);
            }
        }

        return $performer instanceof Performer ? $performer : null;
    }

    /**
     * @param  list<string>  $uuids
     * @return Collection<int, Performer>
     */
    public function findByUuidsForDisplay(array $uuids): Collection
    {
        $uuidToCanonicalId = Performer::query()->whereIn('uuid', $uuids)->get(['id', 'uuid', 'canonical_performer_id'])
            ->mapWithKeys(static fn(Performer $performer): array => [
                (string) $performer->uuid => (int) ($performer->canonical_performer_id ?? $performer->id),
            ]);
        $canonicalIds = $uuidToCanonicalId->unique()->values()->all();
        if ($canonicalIds === []) {
            return new Collection();
        }

        /** @var Collection<int, Performer> $performers */
        $performers = $this->newQuery()
            ->whereNull('canonical_performer_id')
            ->whereIn('id', $canonicalIds)
            ->with(['aliases', 'sources'])
            ->withCount('movies')
            ->get();
        $performersById = $performers->keyBy(static fn(Performer $performer): int => (int) $performer->id);
        $resolved = [];
        $seen = [];
        foreach ($uuids as $uuid) {
            $canonicalId = $uuidToCanonicalId->get($uuid);
            if (! is_int($canonicalId) || isset($seen[$canonicalId])) {
                continue;
            }
            $performer = $performersById->get($canonicalId);
            if (! $performer instanceof Performer) {
                continue;
            }
            $seen[$canonicalId] = true;
            $resolved[] = $performer;
        }

        return new Collection($resolved);
    }
}
