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
            ->with('sources')
            ->withCount('movies');
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
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $like = $this->likeOperator();
        $q = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : '';

        if ($q !== '') {
            $pattern = '%' . $q . '%';
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

        foreach (['cup', 'blood_type', 'location'] as $column) {
            $value = $filters[$column] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $query->where($column, $like, '%' . trim($value) . '%');
            }
        }
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
