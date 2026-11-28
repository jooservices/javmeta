<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\CatalogueRepository;
use App\Repositories\Api\V2\MeMarkRepository;
use App\Services\Api\V2\Catalogue\Formatter;

/** Followed performers, makers, series, genres and labels (marks of kind "follow" in the v1 UI table). */
final class Follows
{
    public const KINDS = ['performer', 'maker', 'series', 'genre', 'label'];

    private const STORED = 'follow';

    public function __construct(
        private readonly MeMarkRepository $marks,
        private readonly CatalogueRepository $catalogue,
        private readonly Formatter $formatter,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        $rows = array_values(array_filter($this->marks->all($userId, self::STORED), static fn(object $row): bool => in_array($row->subject, self::KINDS, true)));
        $ids = array_map(static fn(object $row): int => (int) $row->key, array_filter($rows, static fn(object $row): bool => $row->subject === 'performer' && ctype_digit((string) $row->key)));
        $performers = [];
        foreach ($this->catalogue->performersByIds(array_values($ids)) as $performer) {
            $performers[(string) $performer->id] = $this->formatter->performer($performer);
        }

        return array_map(static fn(object $row): array => [
            'kind' => $row->subject,
            'ref' => (string) $row->key,
            'created_at' => Timestamp::iso((string) $row->created_at),
            'performer' => $row->subject === 'performer' ? ($performers[(string) $row->key] ?? null) : null,
        ], $rows);
    }

    /** @return array{kind: string, ref: string, following: bool} */
    public function state(int $userId, string $kind, string $ref): array
    {
        $this->check($kind);

        return ['kind' => $kind, 'ref' => $ref, 'following' => $this->marks->exists($userId, self::STORED, $kind, $ref)];
    }

    /** @return array{kind: string, ref: string, following: bool} */
    public function put(int $userId, string $kind, string $ref): array
    {
        $this->check($kind);
        $this->marks->put($userId, self::STORED, $kind, $ref);

        return ['kind' => $kind, 'ref' => $ref, 'following' => true];
    }

    public function remove(int $userId, string $kind, string $ref): void
    {
        $this->check($kind);
        $this->marks->remove($userId, self::STORED, $kind, $ref);
    }

    public function count(int $userId): int
    {
        return $this->marks->count($userId, [self::STORED]);
    }

    private function check(string $kind): void
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw ProblemException::notFound('follow_kind_not_found', 'Follow kind must be one of ' . implode(', ', self::KINDS) . '.');
        }
    }
}
