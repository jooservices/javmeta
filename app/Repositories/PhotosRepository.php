<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Photos\GalleryId;
use App\Data\Photos\PhotosGalleryQuery;
use App\Data\Photos\PhotosGroupQuery;
use App\Models\Movie;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Support\Code\NormalizedCode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Read-only photo/gallery aggregations over performer_media and movie_media. */
final class PhotosRepository extends BusinessQueryRepository
{
    public function __construct(PerformerMedia $model)
    {
        parent::__construct($model);
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function groups(PhotosGroupQuery $query): array
    {
        return match ($query->by) {
            'performer' => $this->performerGroups($query),
            'movie' => $this->movieGroups($query),
            default => $this->galleryGroups($query),
        };
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function galleries(PhotosGalleryQuery $query): array
    {
        if ($query->performerId !== null) {
            return $this->listGalleries('performer', $query->performerId, $query);
        }

        if ($query->movieCode === null || $query->movieCode === '') {
            return ['items' => [], 'total' => 0];
        }

        $movieId = $this->movieIdByCode($query->movieCode);
        if ($movieId === null) {
            return ['items' => [], 'total' => 0];
        }

        return $this->listGalleries('movie', $movieId, $query);
    }

    /** @return array{gallery: array<string, mixed>|null, items: list<array<string, mixed>>, total: int} */
    public function galleryImages(GalleryId $id, int $page, int $perPage): array
    {
        $table = $id->type === 'performer' ? 'performer_media' : 'movie_media';
        $ownerColumn = $id->type === 'performer' ? 'performer_id' : 'movie_id';
        $keyExpr = $this->galleryKeyExpr('m');
        $positionExpr = $this->positionExpr('m');

        $galleryKey = $id->galleryKey;
        if ($id->mediaId !== null) {
            $galleryKey = (string) ($this->tableQuery($table . ' as anchor')
                ->where('anchor.id', $id->mediaId)
                ->where('anchor.kind', 'gallery')
                ->where('anchor.' . $ownerColumn, $id->ownerId)
                ->where('anchor.source_slug', $id->source)
                ->selectRaw($this->galleryKeyExpr('anchor') . ' as gallery_key')
                ->value('gallery_key') ?? '');

            if ($galleryKey === '') {
                return ['gallery' => null, 'items' => [], 'total' => 0];
            }
        }

        $base = $this->tableQuery($table . ' as m')
            ->where('m.kind', 'gallery')
            ->where('m.' . $ownerColumn, $id->ownerId)
            ->where('m.source_slug', $id->source)
            ->whereRaw($keyExpr . ' = ?', [$galleryKey]);

        $total = (clone $base)->count();
        if ($total === 0) {
            return ['gallery' => null, 'items' => [], 'total' => 0];
        }

        $first = (clone $base)
            ->orderByRaw($positionExpr . ' asc')
            ->orderBy('m.id')
            ->first(['m.url', 'm.meta', 'm.source_slug', 'm.crawled_at']);

        $rows = (clone $base)
            ->orderByRaw($positionExpr . ' asc')
            ->orderBy('m.id')
            ->forPage($page, $perPage)
            ->get(['m.id', 'm.url', 'm.meta', 'm.source_slug', 'm.crawled_at']);

        $items = [];
        foreach ($rows as $row) {
            $meta = $this->metaOf($row->meta);
            $items[] = [
                'url' => (string) $row->url,
                'source' => (string) $row->source_slug,
                'crawled_at' => $this->atom($row->crawled_at),
                'position' => $this->integerPosition($meta['position'] ?? null),
            ];
        }

        $gallery = [
            'id' => $id->encode(),
            'type' => $id->type,
            'owner_id' => $id->ownerId,
            'source' => $id->source,
            'gallery_key' => $galleryKey,
            'title' => is_string(($this->metaOf($first?->meta)['gallery_title'] ?? null))
                ? $this->metaOf($first?->meta)['gallery_title']
                : null,
            'url' => is_string(($this->metaOf($first?->meta)['gallery_url'] ?? null))
                ? $this->metaOf($first?->meta)['gallery_url']
                : null,
            'photo_count' => $total,
            'cover_url' => $first !== null ? (string) $first->url : null,
        ];
        $gallery = array_merge($gallery, $this->ownerSummary($id->type, $id->ownerId));

        return ['gallery' => $gallery, 'items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array{name: string, id?: int|string, count: int}>, total: int, hidden_below_min: int} */
    public function facetValues(string $facet, string $q, int $page, int $perPage, int $minCount, string $sort): array
    {
        $grouped = $facet === 'source'
            ? $this->sourceFacetQuery($q)
            : $this->performerFacetQuery($q);
        $named = $this->databaseQuery()->fromSub(clone $grouped, 'facet_rows')->count();

        $visible = clone $grouped;
        $visible->havingRaw($facet === 'source' ? 'sum(facet_source.count) >= ?' : 'count(*) >= ?', [$minCount]);
        $total = $this->databaseQuery()->fromSub(clone $visible, 'visible_facets')->count();

        $rows = $this->databaseQuery()
            ->fromSub($visible, 'facet_rows')
            ->select('facet_rows.name', 'facet_rows.count');
        if ($facet === 'performer') {
            $rows->addSelect('facet_rows.id');
        }
        if ($sort === 'name') {
            $rows->orderBy('facet_rows.name');
        } else {
            $rows->orderByDesc('facet_rows.count')->orderBy('facet_rows.name');
        }
        $pageRows = $rows->forPage($page, $perPage)->get();

        $items = [];
        foreach ($pageRows as $row) {
            $item = ['name' => (string) $row->name, 'count' => (int) $row->count];
            if (isset($row->id)) {
                $item['id'] = (int) $row->id;
            }
            $items[] = $item;
        }

        return ['items' => $items, 'total' => $total, 'hidden_below_min' => max(0, $named - $total)];
    }

    private function sourceFacetQuery(string $q): Builder
    {
        $performer = $this->tableQuery('performer_media')->where('kind', 'gallery');
        $movie = $this->tableQuery('movie_media')->where('kind', 'gallery');
        if ($q !== '') {
            $pattern = $this->containsLikePattern($q);
            $performer->whereRaw("lower(source_slug) like lower(?) escape '!'", [$pattern]);
            $movie->whereRaw("lower(source_slug) like lower(?) escape '!'", [$pattern]);
        }

        $union = $performer->selectRaw('source_slug as name, count(*) as count')->groupBy('source_slug')
            ->unionAll($movie->selectRaw('source_slug as name, count(*) as count')->groupBy('source_slug'));

        return $this->databaseQuery()
            ->fromSub($union, 'facet_source')
            ->selectRaw('facet_source.name as name, sum(facet_source.count) as count')
            ->groupBy('facet_source.name');
    }

    private function performerFacetQuery(string $q): Builder
    {
        $query = $this->tableQuery('performer_media as m')
            ->join('performers as p', 'p.id', '=', 'm.performer_id')
            ->where('m.kind', 'gallery');
        if ($q !== '') {
            $pattern = $this->containsLikePattern($q);
            $query->where(static function (Builder $search) use ($pattern): void {
                $search->whereRaw("lower(coalesce(p.name_romaji, '')) like lower(?) escape '!'", [$pattern])
                    ->orWhereRaw("lower(coalesce(p.name_kanji, '')) like lower(?) escape '!'", [$pattern])
                    ->orWhereRaw("lower(coalesce(p.name_kana, '')) like lower(?) escape '!'", [$pattern])
                    ->orWhereRaw("cast(p.id as text) like ? escape '!'", [$pattern]);
            });
        }

        return $query
            ->selectRaw('p.id as id, coalesce(p.name_romaji, p.name_kanji, cast(p.id as text)) as name, count(*) as count')
            ->groupBy('p.id', 'p.name_romaji', 'p.name_kanji');
    }

    private function containsLikePattern(string $value): string
    {
        return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value) . '%';
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function performerGroups(PhotosGroupQuery $query): array
    {
        $base = $this->tableQuery('performer_media as m')
            ->join('performers as p', 'p.id', '=', 'm.performer_id')
            ->where('m.kind', 'gallery');
        $this->applySource($base, $query->source, 'm');
        if ($query->movieCode !== null) {
            $movieId = $this->movieIdByCode($query->movieCode);
            if ($movieId === null) {
                return ['items' => [], 'total' => 0];
            }
            $this->wherePerformerHasMovie($base, $movieId);
        }
        if ($query->performerId !== null) {
            $base->where('m.performer_id', $query->performerId);
        }
        if ($query->q !== '') {
            $pattern = $this->containsLikePattern($query->q);
            $base->where(function (Builder $w) use ($pattern): void {
                $w->whereRaw("p.name_romaji like ? escape '!'", [$pattern])
                    ->orWhereRaw("p.name_kanji like ? escape '!'", [$pattern])
                    ->orWhereRaw("p.name_kana like ? escape '!'", [$pattern]);
            });
        }

        $grouped = (clone $base)
            ->selectRaw('m.performer_id as owner_id, count(*) as photo_count, count(distinct ' . $this->galleryIdentityExpr('m') . ') as gallery_count')
            ->groupBy('m.performer_id');

        $total = $this->databaseQuery()->fromSub($grouped, 'g')->count();
        $pageRows = $this->databaseQuery()
            ->fromSub($grouped, 'g')
            ->orderByDesc('g.photo_count')
            ->orderBy('g.owner_id')
            ->forPage($query->page, $query->perPage)
            ->get();

        $ids = $pageRows->pluck('owner_id')->map(static fn($id): int => (int) $id)->all();
        $covers = $this->coversFor('performer', $ids, $query->source);
        $summaries = $this->performerSummaries($ids);

        $items = [];
        foreach ($pageRows as $row) {
            $id = (int) $row->owner_id;
            $cover = $covers[$id] ?? ['cover_url' => null, 'preview_urls' => []];
            $items[] = [
                'by' => 'performer',
                'id' => $id,
                'photo_count' => (int) $row->photo_count,
                'gallery_count' => (int) $row->gallery_count,
                'cover_url' => $cover['cover_url'],
                'preview_urls' => $cover['preview_urls'],
                'performer' => $summaries[$id] ?? null,
                'movie' => null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function movieGroups(PhotosGroupQuery $query): array
    {
        $base = $this->tableQuery('movie_media as m')
            ->join('movies as mv', 'mv.id', '=', 'm.movie_id')
            ->where('m.kind', 'gallery');
        $this->applySource($base, $query->source, 'm');
        if ($query->movieCode !== null) {
            $normalized = $this->normalizedMovieCode($query->movieCode);
            if ($normalized === null) {
                return ['items' => [], 'total' => 0];
            }
            $base->where('mv.code_normalized', $normalized);
        }
        if ($query->performerId !== null) {
            $this->whereMovieHasPerformer($base, $query->performerId);
        }
        if ($query->q !== '') {
            $pattern = $this->containsLikePattern($query->q);
            $base->where(function (Builder $w) use ($pattern): void {
                $w->whereRaw("mv.display_code like ? escape '!'", [$pattern])
                    ->orWhereRaw("mv.title_en like ? escape '!'", [$pattern])
                    ->orWhereRaw("mv.title_jp like ? escape '!'", [$pattern]);
            });
        }

        $grouped = (clone $base)
            ->selectRaw('m.movie_id as owner_id, count(*) as photo_count, count(distinct ' . $this->galleryIdentityExpr('m') . ') as gallery_count')
            ->groupBy('m.movie_id');

        $total = $this->databaseQuery()->fromSub($grouped, 'g')->count();
        $pageRows = $this->databaseQuery()
            ->fromSub($grouped, 'g')
            ->orderByDesc('g.photo_count')
            ->orderBy('g.owner_id')
            ->forPage($query->page, $query->perPage)
            ->get();

        $ids = $pageRows->pluck('owner_id')->map(static fn($id): int => (int) $id)->all();
        $covers = $this->coversFor('movie', $ids, $query->source);
        $summaries = $this->movieSummaries($ids);

        $items = [];
        foreach ($pageRows as $row) {
            $id = (int) $row->owner_id;
            $cover = $covers[$id] ?? ['cover_url' => null, 'preview_urls' => []];
            $items[] = [
                'by' => 'movie',
                'id' => $id,
                'photo_count' => (int) $row->photo_count,
                'gallery_count' => (int) $row->gallery_count,
                'cover_url' => $cover['cover_url'],
                'preview_urls' => $cover['preview_urls'],
                'performer' => null,
                'movie' => $summaries[$id] ?? null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function galleryGroups(PhotosGroupQuery $query): array
    {
        $movieId = null;
        if ($query->movieCode !== null) {
            $movieId = $this->movieIdByCode($query->movieCode);
            if ($movieId === null) {
                return ['items' => [], 'total' => 0];
            }
        }

        $performer = $this->galleryAggregate('performer', $query, $movieId);
        $movie = $this->galleryAggregate('movie', $query, $movieId);
        $union = $performer->unionAll($movie);
        $total = $this->databaseQuery()->fromSub($union, 'u')->count();
        $pageRows = $this->databaseQuery()
            ->fromSub($union, 'u')
            ->orderByDesc('u.photo_count')
            ->orderBy('u.type')
            ->orderBy('u.owner_id')
            ->orderBy('u.source_slug')
            ->orderBy('u.gallery_key')
            ->forPage($query->page, $query->perPage)
            ->get();

        $galleryRows = $pageRows->all();
        $performerIds = [];
        $movieIds = [];
        foreach ($galleryRows as $row) {
            if ($row->type === 'performer') {
                $performerIds[] = (int) $row->owner_id;
            } else {
                $movieIds[] = (int) $row->owner_id;
            }
        }
        $performerSummaries = $this->performerSummaries(array_values(array_unique($performerIds)));
        $movieSummaries = $this->movieSummaries(array_values(array_unique($movieIds)));
        $performerPreviews = $this->previewsForGalleryRows('performer', $galleryRows, 3);
        $moviePreviews = $this->previewsForGalleryRows('movie', $galleryRows, 3);

        $items = [];
        foreach ($pageRows as $row) {
            $type = (string) $row->type;
            $ownerId = (int) $row->owner_id;
            $source = (string) $row->source_slug;
            $galleryKey = (string) $row->gallery_key;
            $galleryId = GalleryId::fromMediaId($type, $ownerId, $source, (int) $row->media_id)->encode();
            $identity = $this->galleryRowIdentity($ownerId, $source, $galleryKey);
            $preview = ($type === 'performer' ? $performerPreviews : $moviePreviews)[$identity] ?? [];
            $items[] = [
                'by' => 'gallery',
                'id' => $galleryId,
                'photo_count' => (int) $row->photo_count,
                'gallery_count' => 1,
                'cover_url' => $preview[0] ?? null,
                'preview_urls' => $preview,
                'title' => $row->title !== null ? (string) $row->title : null,
                'source' => $source,
                'performer' => $type === 'performer' ? ($performerSummaries[$ownerId] ?? null) : null,
                'movie' => $type === 'movie' ? ($movieSummaries[$ownerId] ?? null) : null,
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    private function galleryAggregate(string $type, PhotosGroupQuery $query, ?int $movieId): Builder
    {
        $table = $type === 'performer' ? 'performer_media' : 'movie_media';
        $owner = $type === 'performer' ? 'performer_id' : 'movie_id';
        $base = $this->tableQuery($table . ' as m')->where('m.kind', 'gallery');
        $this->applySource($base, $query->source, 'm');
        if ($type === 'performer' && $query->performerId !== null) {
            $base->where('m.performer_id', $query->performerId);
        }
        if ($type === 'performer' && $movieId !== null) {
            $this->wherePerformerHasMovie($base, $movieId);
        }
        if ($type === 'movie' && $query->movieCode !== null) {
            $base->where('m.movie_id', $movieId ?? 0);
        }
        if ($type === 'movie' && $query->performerId !== null) {
            $this->whereMovieHasPerformer($base, $query->performerId);
        }
        if ($query->q !== '') {
            $pattern = $this->containsLikePattern($query->q);
            if ($type === 'performer') {
                $base->join('performers as p', 'p.id', '=', 'm.performer_id')
                    ->where(function (Builder $w) use ($pattern): void {
                        $w->whereRaw("p.name_romaji like ? escape '!'", [$pattern])
                            ->orWhereRaw("p.name_kanji like ? escape '!'", [$pattern])
                            ->orWhereRaw("p.name_kana like ? escape '!'", [$pattern]);
                    });
            } else {
                $base->join('movies as mv', 'mv.id', '=', 'm.movie_id')
                    ->where(function (Builder $w) use ($pattern): void {
                        $w->whereRaw("mv.display_code like ? escape '!'", [$pattern])
                            ->orWhereRaw("mv.title_en like ? escape '!'", [$pattern])
                            ->orWhereRaw("mv.title_jp like ? escape '!'", [$pattern]);
                    });
            }
        }

        $key = $this->galleryKeyExpr('m');
        $title = $this->jsonText('m', 'gallery_title');

        return (clone $base)
            ->selectRaw('? as type, m.' . $owner . ' as owner_id, m.source_slug, ' . $key . ' as gallery_key, min(m.id) as media_id, count(*) as photo_count, min(' . $title . ') as title', [$type])
            ->groupBy('m.' . $owner, 'm.source_slug', DB::raw($key));
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    private function listGalleries(string $type, int $ownerId, PhotosGalleryQuery $query): array
    {
        $table = $type === 'performer' ? 'performer_media' : 'movie_media';
        $ownerColumn = $type === 'performer' ? 'performer_id' : 'movie_id';
        $key = $this->galleryKeyExpr('m');
        $title = $this->jsonText('m', 'gallery_title');
        $base = $this->tableQuery($table . ' as m')
            ->where('m.kind', 'gallery')
            ->where('m.' . $ownerColumn, $ownerId);
        $this->applySource($base, $query->source, 'm');
        if ($type === 'performer' && $query->movieCode !== null) {
            $movieId = $this->movieIdByCode($query->movieCode);
            if ($movieId === null) {
                return ['items' => [], 'total' => 0];
            }
            $this->wherePerformerHasMovie($base, $movieId);
        }
        if ($query->q !== '') {
            $pattern = $this->containsLikePattern($query->q);
            $matchingKey = $this->galleryKeyExpr('matched');
            $base->whereExists(function (Builder $matching) use ($table, $ownerColumn, $ownerId, $pattern, $matchingKey, $key): void {
                $matching->selectRaw('1')
                    ->from($table . ' as matched')
                    ->where('matched.kind', 'gallery')
                    ->where('matched.' . $ownerColumn, $ownerId)
                    ->whereColumn('matched.source_slug', 'm.source_slug')
                    ->whereRaw($matchingKey . ' = ' . $key)
                    ->whereRaw($this->jsonText('matched', 'gallery_title') . " like ? escape '!'", [$pattern]);
            });
        }

        $grouped = (clone $base)
            ->selectRaw('m.source_slug, ' . $key . ' as gallery_key, min(m.id) as media_id, count(*) as photo_count, min(' . $title . ') as title')
            ->groupBy('m.source_slug', DB::raw($key));

        $total = $this->databaseQuery()->fromSub($grouped, 'g')->count();
        $pageRows = $this->databaseQuery()
            ->fromSub($grouped, 'g')
            ->orderByDesc('g.photo_count')
            ->orderBy('g.source_slug')
            ->orderBy('g.gallery_key')
            ->forPage($query->page, $query->perPage)
            ->get();

        $owner = $this->ownerSummary($type, $ownerId);
        $galleryRows = $pageRows->map(static fn($row): array => [
            'owner_id' => $ownerId,
            'source_slug' => (string) $row->source_slug,
            'gallery_key' => (string) $row->gallery_key,
        ])->all();
        $previews = $this->previewsForGalleryRows($type, $galleryRows, 3);
        $items = [];
        foreach ($pageRows as $row) {
            $source = (string) $row->source_slug;
            $galleryKey = (string) $row->gallery_key;
            $preview = $previews[$this->galleryRowIdentity($ownerId, $source, $galleryKey)] ?? [];
            $items[] = array_merge([
                'id' => GalleryId::fromMediaId($type, $ownerId, $source, (int) $row->media_id)->encode(),
                'type' => $type,
                'source' => $source,
                'gallery_key' => $galleryKey,
                'title' => $row->title !== null ? (string) $row->title : null,
                'photo_count' => (int) $row->photo_count,
                'cover_url' => $preview[0] ?? null,
                'preview_urls' => $preview,
            ], $owner);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<int> $ownerIds
     * @return array<int, array{cover_url: ?string, preview_urls: list<string>}>
     */
    private function coversFor(string $type, array $ownerIds, string $source): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $table = $type === 'performer' ? 'performer_media' : 'movie_media';
        $ownerColumn = $type === 'performer' ? 'performer_id' : 'movie_id';
        $position = $this->positionExpr('m');
        $ranked = $this->tableQuery($table . ' as m')
            ->where('m.kind', 'gallery')
            ->whereIn('m.' . $ownerColumn, $ownerIds);
        $this->applySource($ranked, $source, 'm');
        $ranked->selectRaw(
            'm.' . $ownerColumn . ' as owner_id, m.url, row_number() over (partition by m.' . $ownerColumn . ' order by ' . $position . ' asc, m.id asc) as preview_rank',
        );
        $rows = $this->databaseQuery()->fromSub($ranked, 'previews')
            ->where('preview_rank', '<=', 3)
            ->orderBy('owner_id')
            ->orderBy('preview_rank')
            ->get();

        $out = array_fill_keys($ownerIds, ['cover_url' => null, 'preview_urls' => []]);
        foreach ($rows as $row) {
            $id = (int) $row->owner_id;
            $out[$id]['preview_urls'][] = (string) $row->url;
        }
        foreach ($out as &$cover) {
            $cover['cover_url'] = $cover['preview_urls'][0] ?? null;
        }
        unset($cover);

        return $out;
    }

    /**
     * @param list<object|array<string, mixed>> $rows
     * @return array<string, list<string>>
     */
    private function previewsForGalleryRows(string $type, array $rows, int $limit): array
    {
        $identities = [];
        foreach ($rows as $row) {
            $typeValue = is_array($row) ? ($row['type'] ?? $type) : ($row->type ?? $type);
            if ($typeValue !== $type) {
                continue;
            }
            $ownerId = (int) (is_array($row) ? $row['owner_id'] : $row->owner_id);
            $source = (string) (is_array($row) ? $row['source_slug'] : $row->source_slug);
            $galleryKey = (string) (is_array($row) ? $row['gallery_key'] : $row->gallery_key);
            $identities[] = [$ownerId, $source, $galleryKey];
        }
        if ($identities === []) {
            return [];
        }

        $table = $type === 'performer' ? 'performer_media' : 'movie_media';
        $ownerColumn = $type === 'performer' ? 'performer_id' : 'movie_id';
        $key = $this->galleryKeyExpr('m');
        $position = $this->positionExpr('m');
        $query = $this->tableQuery($table . ' as m')->where('m.kind', 'gallery')
            ->where(static function (Builder $match) use ($identities, $ownerColumn, $key): void {
                foreach ($identities as [$ownerId, $source, $galleryKey]) {
                    $match->orWhere(static function (Builder $identity) use ($ownerColumn, $key, $ownerId, $source, $galleryKey): void {
                        $identity->where('m.' . $ownerColumn, $ownerId)
                            ->where('m.source_slug', $source)
                            ->whereRaw($key . ' = ?', [$galleryKey]);
                    });
                }
            })
            ->selectRaw(
                'm.' . $ownerColumn . ' as owner_id, m.source_slug, ' . $key . ' as gallery_key, m.url, row_number() over (partition by m.' . $ownerColumn . ', m.source_slug, ' . $key . ' order by ' . $position . ' asc, m.id asc) as preview_rank',
            );

        $results = $this->databaseQuery()->fromSub($query, 'previews')
            ->where('preview_rank', '<=', $limit)
            ->orderBy('owner_id')
            ->orderBy('source_slug')
            ->orderBy('gallery_key')
            ->orderBy('preview_rank')
            ->get();
        $out = [];
        foreach ($results as $row) {
            $identity = $this->galleryRowIdentity((int) $row->owner_id, (string) $row->source_slug, (string) $row->gallery_key);
            $out[$identity][] = (string) $row->url;
        }

        return $out;
    }

    private function galleryRowIdentity(int $ownerId, string $source, string $galleryKey): string
    {
        return json_encode([$ownerId, $source, $galleryKey], JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function performerSummaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->modelQuery(Performer::class)
            ->whereIn('id', $ids)
            ->get(['id', 'uuid', 'name_romaji', 'name_kanji', 'name_kana', 'image_url']);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'name_romaji' => $row->name_romaji,
                'name_kanji' => $row->name_kanji,
                'name_kana' => $row->name_kana,
                'image_url' => $row->image_url,
            ];
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function movieSummaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->modelQuery(Movie::class)
            ->whereIn('id', $ids)
            ->get(['id', 'uuid', 'display_code', 'title_en', 'title_jp', 'cover_url', 'cover_thumb_url']);
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->id] = [
                'id' => (int) $row->id,
                'uuid' => (string) $row->uuid,
                'code' => (string) $row->display_code,
                'title_en' => $row->title_en,
                'title_jp' => $row->title_jp,
                'cover_url' => $row->cover_url,
                'cover_thumb_url' => $row->cover_thumb_url,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function ownerSummary(string $type, int $ownerId): array
    {
        if ($type === 'performer') {
            return ['performer' => $this->performerSummaries([$ownerId])[$ownerId] ?? null, 'movie' => null];
        }

        return ['performer' => null, 'movie' => $this->movieSummaries([$ownerId])[$ownerId] ?? null];
    }

    private function movieIdByCode(string $code): ?int
    {
        $normalized = $this->normalizedMovieCode($code);
        if ($normalized === null) {
            return null;
        }

        $id = $this->modelQuery(Movie::class)->where('code_normalized', $normalized)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function normalizedMovieCode(string $code): ?string
    {
        try {
            return NormalizedCode::from($code)->value();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function applySource(Builder $query, string $source, string $alias): void
    {
        if ($source !== '') {
            $query->where($alias . '.source_slug', $source);
        }
    }

    private function whereMovieHasPerformer(Builder $query, int $performerId): void
    {
        $query->whereExists(static function (Builder $relationship) use ($performerId): void {
            $relationship->selectRaw('1')
                ->from('movie_performers as mp')
                ->whereColumn('mp.movie_id', 'm.movie_id')
                ->where('mp.performer_id', $performerId);
        });
    }

    private function wherePerformerHasMovie(Builder $query, int $movieId): void
    {
        $query->whereExists(static function (Builder $relationship) use ($movieId): void {
            $relationship->selectRaw('1')
                ->from('movie_performers as mp')
                ->whereColumn('mp.performer_id', 'm.performer_id')
                ->where('mp.movie_id', $movieId);
        });
    }

    private function galleryKeyExpr(string $alias): string
    {
        $galleryId = $this->jsonText($alias, 'gallery_id');
        $galleryUrl = $this->jsonText($alias, 'gallery_url');

        return "coalesce(nullif(" . $galleryId . ", ''), nullif(" . $galleryUrl . ", ''), " . $alias . '.url)';
    }

    private function galleryIdentityExpr(string $alias): string
    {
        return 'cast(length(' . $alias . '.source_slug) as varchar) || ' . "':' || " . $alias . '.source_slug || ' . $this->galleryKeyExpr($alias);
    }

    private function positionExpr(string $alias): string
    {
        $pos = $this->jsonText($alias, 'position');
        if ($this->driverName() === 'pgsql') {
            return 'case when (' . $pos . ") ~ '^-?[0-9]{1,19}$' then case when (" . $pos . ')::numeric between -9223372036854775808 and 9223372036854775807 then (' . $pos . ')::bigint else 9223372036854775807 end else 9223372036854775807 end';
        }

        return "case when json_type(" . $alias . ".meta, '$.position') = 'integer' and typeof(" . $pos . ') = \'integer\' then ' . $pos . ' else 9223372036854775807 end';
    }

    private function jsonText(string $alias, string $key): string
    {
        if ($this->driverName() === 'pgsql') {
            return $alias . ".meta->>'" . $key . "'";
        }

        return 'json_extract(' . $alias . ".meta, '$." . $key . "')";
    }

    /** @return array<string, mixed> */
    private function metaOf(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function integerPosition(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value) || preg_match('/\A-?(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    private function atom(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
