<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\Envelope;
use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\MeCatalogueRepository;
use App\Repositories\Api\V2\MeMarkRepository;
use App\Services\Api\V2\Catalogue\CatalogueService;

/** Favourite, watched, want and hidden marks on movies and performers. The rows are those of the v1 UI (user_marks). */
final class Marks
{
    /** The kinds of the contract and the kinds the v1 UI stores. */
    private const STORED = ['favorite' => 'favourite', 'watched' => 'seen', 'want' => 'watch_later', 'hidden' => 'hidden'];

    private const TARGETS = ['movie', 'performer'];

    public function __construct(
        private readonly MeMarkRepository $marks,
        private readonly MeCatalogueRepository $movies,
        private readonly CatalogueService $catalogue,
    ) {}

    /** @return list<string> the stored kinds that count as marks */
    public static function storedKinds(): array
    {
        return array_values(self::STORED);
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function list(int $userId, array $query): array
    {
        $kinds = $this->kinds((string) ($query['kind'] ?? ''));
        $target = isset($query['target']) ? (string) $query['target'] : null;
        $page = Page::from($query);
        $result = $this->marks->page($userId, array_map(static fn(string $kind): string => self::STORED[$kind], $kinds), $target, $page->size, $page->offset());
        $names = array_flip(self::STORED);

        return [
            'data' => array_map(static fn(object $row): array => ['id' => (int) $row->id, 'kind' => $names[$row->kind], 'target' => $row->subject, 'ref' => (string) $row->key, 'created_at' => Timestamp::iso((string) $row->created_at)], $result['rows']),
            'meta' => Envelope::pagination($page->number, $page->size, $result['total']),
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function movies(int $userId, array $query): array
    {
        $kind = (string) ($query['kind'] ?? 'favorite');
        $page = Page::from($query);
        $result = $this->marks->page($userId, [self::STORED[$this->known($kind)]], 'movie', $page->size, $page->offset());
        $codes = array_map(static fn(object $row): string => (string) $row->key, $result['rows']);

        return ['data' => $this->catalogue->movieSummaries($this->movies->moviesByCodes($codes)), 'meta' => Envelope::pagination($page->number, $page->size, $result['total'])];
    }

    /** @return array{kind: string, target: string, ref: string, on: bool} */
    public function state(int $userId, string $kind, string $target, string $ref): array
    {
        $this->check($kind, $target);

        return $this->answer($kind, $target, $ref, $this->marks->exists($userId, self::STORED[$kind], $target, $ref));
    }

    /** @return array{kind: string, target: string, ref: string, on: bool} */
    public function put(int $userId, string $kind, string $target, string $ref): array
    {
        $this->check($kind, $target);
        $this->marks->put($userId, self::STORED[$kind], $target, $ref);

        return $this->answer($kind, $target, $ref, true);
    }

    public function remove(int $userId, string $kind, string $target, string $ref): void
    {
        $this->check($kind, $target);
        $this->marks->remove($userId, self::STORED[$kind], $target, $ref);
    }

    public function count(int $userId): int
    {
        return $this->marks->count($userId, self::storedKinds());
    }

    /** @return list<string> */
    private function kinds(string $list): array
    {
        $kinds = array_values(array_filter(array_map('trim', explode(',', $list)), static fn(string $kind): bool => $kind !== ''));
        if ($kinds === []) {
            return array_keys(self::STORED);
        }

        return array_map($this->known(...), $kinds);
    }

    private function known(string $kind): string
    {
        return isset(self::STORED[$kind]) ? $kind : throw ProblemException::invalid('Unknown mark kind.', [['field' => 'kind', 'code' => 'in']]);
    }

    private function check(string $kind, string $target): void
    {
        if (! isset(self::STORED[$kind])) {
            throw ProblemException::notFound('mark_kind_not_found', 'Mark kind must be one of ' . implode(', ', array_keys(self::STORED)) . '.');
        }

        if (! in_array($target, self::TARGETS, true)) {
            throw ProblemException::notFound('mark_target_not_found', 'Mark target must be movie or performer.');
        }
    }

    /** @return array{kind: string, target: string, ref: string, on: bool} */
    private function answer(string $kind, string $target, string $ref, bool $on): array
    {
        return ['kind' => $kind, 'target' => $target, 'ref' => $ref, 'on' => $on];
    }
}
