<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Me;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\MeMarkRepository;

/** Searches the user keeps (marks of kind "saved_search": the key holds the name and the query as JSON). */
final class SavedSearches
{
    private const STORED = 'saved_search';

    /** The target of the contract and the subject the v1 UI stores. */
    private const SUBJECTS = ['movie' => 'movies', 'performer' => 'performers'];

    public function __construct(private readonly MeMarkRepository $marks) {}

    /** @return list<array<string, mixed>> */
    public function list(int $userId): array
    {
        return array_map($this->view(...), $this->marks->all($userId, self::STORED));
    }

    /** @return array<string, mixed> */
    public function create(int $userId, string $name, string $query, string $target): array
    {
        $name = trim($name);
        $id = $this->marks->replaceSavedSearch($userId, self::SUBJECTS[$target], $name, $this->key($name, $query));

        return $this->view($this->marks->find($userId, $id, self::STORED) ?? throw ProblemException::notFound('saved_search_not_found', 'No such saved search.'));
    }

    /** @return array<string, mixed> */
    public function update(int $userId, int $id, ?string $name, ?string $query): array
    {
        $row = $this->marks->find($userId, $id, self::STORED) ?? throw ProblemException::notFound('saved_search_not_found', 'No such saved search.');

        if ($name === null && $query === null) {
            throw ProblemException::invalid('Nothing to change.');
        }

        $stored = $this->decode($row);
        $this->marks->updateKey($id, $this->key($name === null ? $stored['name'] : trim($name), $query ?? $stored['query']));

        return $this->view($this->marks->find($userId, $id, self::STORED) ?? $row);
    }

    public function delete(int $userId, int $id): void
    {
        $this->marks->removeById($userId, $id, self::STORED);
    }

    public function count(int $userId): int
    {
        return $this->marks->count($userId, [self::STORED]);
    }

    private function key(string $name, string $query): string
    {
        return json_encode(['name' => $name, 'query' => $query], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array{name: string, query: string} */
    private function decode(object $row): array
    {
        $stored = json_decode((string) $row->key, true);

        return ['name' => is_array($stored) ? (string) ($stored['name'] ?? '') : '', 'query' => is_array($stored) ? (string) ($stored['query'] ?? '') : ''];
    }

    /** @return array<string, mixed> */
    private function view(object $row): array
    {
        $target = array_search($row->subject, self::SUBJECTS, true);

        return ['id' => (int) $row->id, 'target' => $target === false ? 'movie' : $target] + $this->decode($row) + ['created_at' => Timestamp::iso((string) $row->created_at)];
    }
}
