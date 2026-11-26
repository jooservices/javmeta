<?php

declare(strict_types=1);

namespace Tests\Support\Api\V2;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Answers the PostgreSQL-only SELECTs of the v2 repositories with canned rows, so services and controllers run for real on SQLite.
 * A rule maps a fragment of the SQL to the rows (list of arrays or objects) or to a closure that gets the bindings and returns them.
 */
trait FakesPostgresRows
{
    /** @var list<array{sql: string, bindings: array<int, mixed>}> */
    protected array $sqlCalls = [];

    /** @param array<string, list<array<string, mixed>|object>|Closure(array<int, mixed>): list<array<string, mixed>|object>> $rules */
    protected function fakeRows(array $rules): void
    {
        $answer = function (string $sql, array $bindings) use ($rules): array {
            $this->sqlCalls[] = ['sql' => $sql, 'bindings' => $bindings];

            foreach ($rules as $fragment => $rows) {
                if (str_contains($sql, (string) $fragment)) {
                    return array_map(static fn(array|object $row): object => (object) $row, $rows instanceof Closure ? $rows($bindings) : $rows);
                }
            }

            throw new RuntimeException('No canned rows for: ' . $sql);
        };

        DB::shouldReceive('select')->andReturnUsing(static fn(string $sql, array $bindings = []): array => $answer($sql, $bindings));
        DB::shouldReceive('selectOne')->andReturnUsing(static fn(string $sql, array $bindings = []): ?object => $answer($sql, $bindings)[0] ?? null);
    }

    /** @return list<array{sql: string, bindings: array<int, mixed>}> the queries whose SQL contains the fragment */
    protected function sqlContaining(string $fragment): array
    {
        return array_values(array_filter($this->sqlCalls, static fn(array $call): bool => str_contains($call['sql'], $fragment)));
    }

    /**
     * @param array<string, mixed> $with
     * @return array<string, mixed> a row of the movie summary query
     */
    protected function movieRow(array $with = []): array
    {
        return $with + [
            'id' => 1, 'code' => 'ABP-001', 'title_jp' => 'タイトル', 'title_en' => 'A Title', 'release_date' => '2024-05-01', 'runtime' => 120, 'censored' => true,
            'maker' => 'Prestige', 'label' => 'Absolutely', 'series' => null, 'score' => '7.5', 'tier' => 2, 'cover_url' => 'https://img/c.jpg', 'cover_thumb_url' => 'https://img/t.jpg',
            'crawled_at' => '2024-05-02 10:00:00', 'genres' => '{Drama,"Big Tits"}', 'performer_ids' => '{7,9}', 'dl_count' => 3, 'hls_count' => 1, 'gallery_count' => 4,
            'sources' => '{a,b}', 'needs_review' => false, 'delisted' => false,
        ];
    }

    /**
     * @param array<string, mixed> $with
     * @return array<string, mixed> a row of the performer summary query
     */
    protected function performerRow(array $with = []): array
    {
        return $with + [
            'id' => 7, 'name_romaji' => 'Yua Mikami', 'name_kanji' => '三上悠亜', 'name_kana' => null, 'birth_date' => '1993-08-16', 'height_cm' => 159, 'bust' => 88, 'waist' => 58, 'hip' => 86,
            'cup' => 'F', 'blood_type' => 'A', 'blood_rh' => '+', 'location' => 'Tokyo', 'movie_count' => 120, 'photo_url' => 'https://img/p.jpg', 'photo_count' => 5, 'debut_date' => '2015-01-01',
            'retired_date' => null, 'aliases' => '{}', 'crawled_at' => '2024-05-02 10:00:00', 'first_seen_at' => '2024-01-01 00:00:00', 'source_count' => 2,
        ];
    }
}
