<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use Illuminate\Support\Facades\DB;

/** The movies of the search tables (rm_movies) that the owner's marks and follows point at. PostgreSQL only. */
final class MeCatalogueRepository
{
    /**
     * Movies by code, in the order of the given codes; codes with no movie are left out.
     *
     * @param list<string> $codes
     * @return list<object>
     */
    public function moviesByCodes(array $codes): array
    {
        if ($codes === []) {
            return [];
        }

        $found = [];
        foreach (DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . ' FROM rm_movies m WHERE m.code = ANY(?::text[])', [$this->textArray($codes)]) as $row) {
            $found[(string) $row->code] = $row;
        }

        return array_values(array_filter(array_map(static fn(string $code): ?object => $found[$code] ?? null, $codes)));
    }

    /**
     * Newest movies of the followed performers, makers, series, genres and labels.
     *
     * @param array<string, list<string>> $follows keyed by follow kind
     * @return array{rows: list<object>, total: int}
     */
    public function feed(array $follows, int $limit, int $offset): array
    {
        $where = 'NOT m.delisted AND (m.performer_ids && ?::int[] OR m.maker = ANY(?::text[]) OR m.series = ANY(?::text[]) OR m.genres && ?::text[] OR m.label = ANY(?::text[]))';
        $bindings = [
            $this->textArray(array_values(array_filter($follows['performer'] ?? [], is_numeric(...)))),
            $this->textArray($follows['maker'] ?? []),
            $this->textArray($follows['series'] ?? []),
            $this->textArray($follows['genre'] ?? []),
            $this->textArray($follows['label'] ?? []),
        ];

        return [
            'rows' => DB::select('SELECT ' . CatalogueRepository::MOVIE_FIELDS . " FROM rm_movies m WHERE {$where} ORDER BY m.crawled_at DESC NULLS LAST, m.id DESC LIMIT {$limit} OFFSET {$offset}", $bindings),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_movies m WHERE {$where}", $bindings)->n,
        ];
    }

    /** @param list<string> $values */
    private function textArray(array $values): string
    {
        return '{' . implode(',', array_map(static fn(string $v): string => '"' . addcslashes($v, '"\\') . '"', $values)) . '}';
    }
}
