<?php

declare(strict_types=1);

namespace App\Repositories\Api\V2;

use App\Services\Api\V2\Catalogue\SqlConditions;
use Illuminate\Support\Facades\DB;

/** Movie covers and performer avatars, newest crawl first. PostgreSQL only. */
final class PhotoCollectionRepository
{
    /** @return array{rows: list<object>, total: int} */
    public function covers(string $term, int $limit, int $offset): array
    {
        [$where, $bindings] = $this->textFilter('m.search_text', $term, 'm.cover_url IS NOT NULL AND NOT m.delisted');

        return [
            'rows' => DB::select("SELECT m.code, m.cover_url, m.cover_thumb_url, m.title_en, m.title_jp FROM rm_movies m WHERE {$where} ORDER BY m.crawled_at DESC NULLS LAST, m.id DESC LIMIT {$limit} OFFSET {$offset}", $bindings),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_movies m WHERE {$where}", $bindings)->n,
        ];
    }

    /** @return array{rows: list<object>, total: int} */
    public function avatars(string $term, int $limit, int $offset): array
    {
        [$where, $bindings] = $this->textFilter('p.search_text', $term, 'p.photo_url IS NOT NULL');

        return [
            'rows' => DB::select("SELECT p.id, p.name_romaji, p.photo_url FROM rm_performers p WHERE {$where} ORDER BY p.crawled_at DESC NULLS LAST, p.id DESC LIMIT {$limit} OFFSET {$offset}", $bindings),
            'total' => (int) DB::selectOne("SELECT count(*) AS n FROM rm_performers p WHERE {$where}", $bindings)->n,
        ];
    }

    /** @return array{0: string, 1: list<string>} */
    private function textFilter(string $column, string $term, string $base): array
    {
        $term = trim($term);

        return $term === '' ? [$base, []] : ["{$base} AND {$column} LIKE ?", [SqlConditions::like($term)]];
    }
}
