<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Facets\FacetListQuery;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Data\Api\PerformerPhotoPageItemDto;
use App\Services\Normalize\PerformerAttributeNormalizer;
use DateTimeInterface;
use InvalidArgumentException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class PerformerRepository extends BusinessQueryRepository
{
    private readonly PerformerAttributeNormalizer $attributeNormalizer;
    /** @var list<string> */
    public const SORTS = ['name', 'updated_at', 'linked_title_count'];

    public function __construct(Performer $model, ?PerformerAttributeNormalizer $attributeNormalizer = null)
    {
        parent::__construct($model);
        $this->attributeNormalizer = $attributeNormalizer ?? new PerformerAttributeNormalizer();
    }

    /** @return \Illuminate\Support\Collection<int, \stdClass> */
    public function facetGroups(string $column): \Illuminate\Support\Collection
    {
        return DB::table('performers')->whereNull('canonical_performer_id')->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw('LOWER(' . $column . ') AS raw_name, COUNT(*) AS performer_count')
            ->groupByRaw('LOWER(' . $column . ')')
            ->get();
    }

    public function unknownBloodTypeCount(): int
    {
        return (int) DB::table('performers')->whereNull('canonical_performer_id')
            ->where(static function ($query): void {
                $query->whereNull('blood_type')->orWhere('blood_type', '=', '');
            })->count();
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
            if ($values === []) {
                continue;
            }

            $canonicalValues = [];
            $partialValues = [];
            $includeUnknown = false;
            foreach ($values as $value) {
                if ($column === 'blood_type' && mb_strtoupper($value, 'UTF-8') === 'UNKNOWN') {
                    $includeUnknown = true;

                    continue;
                }
                $canonical = $this->attributeNormalizer->facetValue($column, $value);
                if ($canonical !== null) {
                    $canonicalValues[$canonical] = true;
                } elseif ($column === 'location') {
                    $partialValues[] = $value;
                }
            }
            if ($canonicalValues === [] && ! $includeUnknown && $partialValues === []) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $canonicalRawValues = [];
            $unknownRawValues = [];
            if ($canonicalValues !== [] || $includeUnknown) {
                $rawValues = DB::table('performers')->whereNotNull($column)->where($column, '<>', '')
                    ->distinct()->pluck($column);
                foreach ($rawValues as $rawValue) {
                    if (! is_string($rawValue)) {
                        continue;
                    }
                    $canonical = $this->attributeNormalizer->facetValue($column, $rawValue);
                    if ($canonical === null) {
                        if ($includeUnknown) {
                            $unknownRawValues[] = $rawValue;
                        }

                        continue;
                    }
                    if (isset($canonicalValues[$canonical])) {
                        $canonicalRawValues[] = $rawValue;
                    }
                }
            }

            $query->where(static function (Builder $any) use ($canonicalValues, $canonicalRawValues, $includeUnknown, $unknownRawValues, $partialValues, $column, $like): void {
                if ($canonicalValues !== []) {
                    $any->orWhereIn($column, $canonicalRawValues);
                }
                if ($includeUnknown) {
                    $any->orWhereNull($column)->orWhere($column, '=', '');
                    if ($unknownRawValues !== []) {
                        $any->orWhereIn($column, $unknownRawValues);
                    }
                }
                foreach ($partialValues as $value) {
                    $any->orWhere($column, $like, '%' . $value . '%');
                }
            });
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
        $includeUnknown = filter_var($filters['include_unknown'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $by = fn(string $column, string $field): array => $this->canonicalFacetCounts($ids, $column, $field, $includeUnknown);
        $decade = DB::getDriverName() === 'sqlite' ? "(CAST(strftime('%Y', birth_date) AS INTEGER) / 10) * 10" : '(EXTRACT(YEAR FROM birth_date)::int / 10) * 10';

        return [
            'total' => (int) DB::table('performers')->whereIn('id', clone $ids)->count(),
            'cups' => $by('cup', 'cup'),
            'blood_types' => $by('blood_type', 'blood_type'),
            'locations' => $by('location', 'location'),
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
     * One paginated facet value list scoped to the current performer filters.
     *
     * @return array{items: list<array<string, mixed>>, total: int, hidden_below_min: int, facet: string}
     */
    public function facetValues(FacetListQuery $query): array
    {
        $filters = $query->filters;
        $builder = $this->newQuery()->whereNull('canonical_performer_id');
        $this->applyFilters($builder, $filters);
        $ids = $builder->toBase()->select('performers.id');
        $includeUnknown = filter_var($filters['include_unknown'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $rows = match ($query->facet) {
            'cups' => $this->allCanonicalFacetCounts($ids, 'cup', 'cup', false),
            'blood_types' => $this->allCanonicalFacetCounts($ids, 'blood_type', 'blood_type', $includeUnknown),
            'locations' => $this->allCanonicalFacetCounts($ids, 'location', 'location', false),
            'sources' => DB::table('performer_sources')->whereIn('performer_id', clone $ids)
                ->selectRaw('site_slug AS name, COUNT(DISTINCT performer_id) AS c')->groupBy('site_slug')->get()
                ->map(static fn($r): array => ['name' => (string) $r->name, 'count' => (int) $r->c])->all(),
            'status' => [
                ['name' => 'active', 'count' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNull('retired_date')->count()],
                ['name' => 'retired', 'count' => (int) DB::table('performers')->whereIn('id', clone $ids)->whereNotNull('retired_date')->count()],
            ],
            default => throw new InvalidArgumentException('Unknown performer facet: ' . $query->facet),
        };

        $needle = mb_strtolower($query->query);
        $matched = [];
        $hidden = 0;
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $count = (int) $row['count'];
            if ($needle !== '' && ! str_contains(mb_strtolower($name), $needle)) {
                continue;
            }
            if ($count < $query->minCount) {
                $hidden++;
                continue;
            }
            $matched[] = $row;
        }

        usort($matched, static function (array $left, array $right) use ($query): int {
            if ($query->sort === 'name') {
                return strcmp((string) $left['name'], (string) $right['name'])
                    ?: ((int) $right['count'] <=> (int) $left['count']);
            }

            return ((int) $right['count'] <=> (int) $left['count'])
                ?: strcmp((string) $left['name'], (string) $right['name']);
        });

        $total = count($matched);
        $items = array_slice($matched, (max(1, $query->page) - 1) * $query->perPage, $query->perPage);

        return [
            'items' => $items,
            'total' => $total,
            'hidden_below_min' => $hidden,
            'facet' => $query->facet,
        ];
    }

    /**
     * @param  \Illuminate\Database\Query\Builder  $ids
     * @return list<array{name: string, count: int}>
     */
    private function allCanonicalFacetCounts(\Illuminate\Database\Query\Builder $ids, string $column, string $field, bool $includeUnknown): array
    {
        $normalizedColumn = 'LOWER(' . $column . ')';
        $groups = DB::table('performers')->whereIn('id', clone $ids)->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw($normalizedColumn . ' AS raw_name, COUNT(*) AS c')->groupByRaw($normalizedColumn)->get();
        $counts = [];
        $unknownCount = $field === 'blood_type' && $includeUnknown
            ? (int) DB::table('performers')->whereIn('id', clone $ids)->where(static function ($query): void {
                $query->whereNull('blood_type')->orWhere('blood_type', '=', '');
            })->count()
            : 0;
        foreach ($groups as $group) {
            $name = $this->attributeNormalizer->facetValue($field, $group->raw_name);
            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + (int) $group->c;
            } elseif ($field === 'blood_type') {
                $unknownCount += (int) $group->c;
            }
        }
        if ($field === 'blood_type' && $includeUnknown) {
            $counts['Unknown'] = $unknownCount;
        }

        return array_map(
            static fn(string $name): array => ['name' => $name, 'count' => $counts[$name]],
            array_keys($counts),
        );
    }

    /** @param \Illuminate\Database\Query\Builder $ids
     *  @return list<array{name: string, count: int}>
     */
    private function canonicalFacetCounts(\Illuminate\Database\Query\Builder $ids, string $column, string $field, bool $includeUnknown): array
    {
        $normalizedColumn = 'LOWER(' . $column . ')';
        $groups = DB::table('performers')->whereIn('id', clone $ids)->whereNotNull($column)->where($column, '<>', '')
            ->selectRaw($normalizedColumn . ' AS raw_name, COUNT(*) AS c')->groupByRaw($normalizedColumn)->get();
        $counts = [];
        $unknownCount = $field === 'blood_type' && $includeUnknown
            ? (int) DB::table('performers')->whereIn('id', clone $ids)->where(function ($query): void {
                $query->whereNull('blood_type')->orWhere('blood_type', '=', '');
            })->count()
            : 0;
        foreach ($groups as $group) {
            $name = $this->attributeNormalizer->facetValue($field, $group->raw_name);
            if ($name !== null) {
                $counts[$name] = ($counts[$name] ?? 0) + (int) $group->c;
            } elseif ($field === 'blood_type') {
                $unknownCount += (int) $group->c;
            }
        }
        if ($field === 'blood_type' && $includeUnknown) {
            $counts['Unknown'] = $unknownCount;
        }

        uksort($counts, static function (string $left, string $right) use (&$counts): int {
            return $counts[$right] <=> $counts[$left] ?: strcmp($left, $right);
        });

        return array_map(fn(string $name): array => ['name' => $name, 'count' => $counts[$name]], array_slice(array_keys($counts), 0, 15));
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
            ->with(['aliases', 'sources'])->withCount('movies');
        $performer = ctype_digit((string) $id)
            ? $query->find((int) $id)
            : $query->where('uuid', (string) $id)->first();

        if (! $performer instanceof Performer) {
            $canonicalId = ctype_digit((string) $id)
                ? Performer::query()->whereKey((int) $id)->value('canonical_performer_id')
                : Performer::query()->where('uuid', (string) $id)->value('canonical_performer_id');
            if (is_numeric($canonicalId)) {
                $performer = $this->newQuery()->whereNull('canonical_performer_id')
                    ->with(['aliases', 'sources'])->withCount('movies')->find((int) $canonicalId);
            }
        }

        if (! $performer instanceof Performer) {
            return null;
        }

        return $performer;
    }

    public function withPhotoPreview(Performer $performer): Performer
    {
        $galleryCount = $this->galleryCount((int) $performer->id);
        $imageCount = $this->imageCount((int) $performer->id);
        $preview = $this->imagePreview((int) $performer->id)
            ->concat($this->galleryPreview((int) $performer->id));
        $performer->setRelation('mediaPreview', $preview);
        $performer->setAttribute('photos_total', $galleryCount + $imageCount);
        $performer->setAttribute('gallery_photos_total', $galleryCount);
        $performer->setAttribute('profile_photos_total', $imageCount);
        $performer->setAttribute('photos_truncated', $galleryCount > 24 || $imageCount > 24);

        return $performer;
    }

    /** @return Collection<int, PerformerMedia> */
    public function galleryPreview(int $performerId, int $limit = 24): Collection
    {
        return PerformerMedia::query()->where('performer_id', $performerId)
            ->where('kind', PerformerMedia::KIND_GALLERY)
            ->orderBy('id')
            ->limit(max(1, min(24, $limit)))
            ->get();
    }

    /** @return Collection<int, PerformerMedia> */
    public function imagePreview(int $performerId, int $limit = 24): Collection
    {
        return PerformerMedia::query()->where('performer_id', $performerId)
            ->where('kind', PerformerMedia::KIND_IMAGE)
            ->orderBy('id')
            ->limit(max(1, min(24, $limit)))
            ->get();
    }

    public function galleryCount(int $performerId): int
    {
        return PerformerMedia::query()->where('performer_id', $performerId)
            ->where('kind', PerformerMedia::KIND_GALLERY)
            ->count();
    }

    public function imageCount(int $performerId): int
    {
        return PerformerMedia::query()->where('performer_id', $performerId)
            ->where('kind', PerformerMedia::KIND_IMAGE)
            ->count();
    }

    /** @return array{items: list<PerformerPhotoPageItemDto>, total: int, page: int, per_page: int, last_page: int, has_more: bool} */
    public function photosPage(int $performerId, string $kind, int $perPage, int $page): array
    {
        $query = PerformerMedia::query()->where('performer_id', $performerId)->where('kind', $kind);
        if ($kind === PerformerMedia::KIND_GALLERY) {
            $query->orderBy('source_slug')
                ->orderBy('meta->gallery_id')
                ->orderBy('meta->gallery_url')
                ->orderByRaw($this->galleryPositionOrderExpression())
                ->orderBy('id');
        } else {
            $query->orderBy('id');
        }
        $total = (clone $query)->count();
        $items = $query->forPage($page, $perPage)->get()
            ->map(static function (PerformerMedia $media): PerformerPhotoPageItemDto {
                $meta = $media->getAttribute('meta');
                $crawledAt = $media->getAttribute('crawled_at');

                return new PerformerPhotoPageItemDto(
                    kind: (string) $media->getAttribute('kind'),
                    url: (string) $media->getAttribute('url'),
                    thumbnailUrl: is_array($meta) && is_string($meta['thumbnail_url'] ?? null) ? $meta['thumbnail_url'] : null,
                    crawledAt: $crawledAt instanceof DateTimeInterface ? $crawledAt->format(DATE_ATOM) : (is_string($crawledAt) ? $crawledAt : null),
                    source: (string) ($media->source_slug ?: 'unknown'),
                    galleryId: is_array($meta) && is_string($meta['gallery_id'] ?? null) ? $meta['gallery_id'] : null,
                    galleryTitle: is_array($meta) && is_string($meta['gallery_title'] ?? null) ? $meta['gallery_title'] : null,
                    galleryUrl: is_array($meta) && is_string($meta['gallery_url'] ?? null) ? $meta['gallery_url'] : null,
                    position: is_array($meta) && is_numeric($meta['position'] ?? null) ? (int) $meta['position'] : null,
                );
            })->all();
        $lastPage = max(1, (int) ceil($total / $perPage));

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'has_more' => $page < $lastPage,
        ];
    }

    private function galleryPositionOrderExpression(): string
    {
        return match (DB::getDriverName()) {
            'pgsql' => "CASE WHEN meta->>'position' ~ '^[0-9]+$' THEN (meta->>'position')::integer ELSE 2147483647 END",
            'mysql' => "CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(meta, '$.position')) REGEXP '^[0-9]+$' THEN CAST(JSON_UNQUOTE(JSON_EXTRACT(meta, '$.position')) AS UNSIGNED) ELSE 2147483647 END",
            default => "CASE WHEN json_type(meta, '$.position') IN ('integer', 'real') THEN CAST(json_extract(meta, '$.position') AS INTEGER) ELSE 2147483647 END",
        };
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
