<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\DownloadRepository;
use App\Repositories\MovieRepository;

/**
 * "Find me download links" asks of signed-in users and guests. A request follows the link search of its movie
 * (queued, searching, found, failed); the requester is a user id or a hash of the guest address.
 */
final class DownloadRequests
{
    private const SEARCH_TO_REQUEST = ['searching' => 'searching', 'found' => 'found', 'not_found' => 'failed', 'failed' => 'failed'];

    public function __construct(
        private readonly DownloadRepository $repository,
        private readonly MovieRepository $movies,
        private readonly LinkSearch $search,
    ) {}

    public static function requester(?int $userId, string $ip): string
    {
        return $userId !== null ? 'user:' . $userId : 'guest:' . substr(hash('sha256', $ip), 0, 32);
    }

    /** @return list<array<string, mixed>> */
    public function mine(string $requester): array
    {
        return array_map(fn(object $row): array => $this->view($this->advance($row)), $this->repository->requestsOf($requester));
    }

    /** @return array<string, mixed> */
    public function one(int $id, string $requester): array
    {
        $row = $this->repository->requestOf($id, $requester) ?? throw ProblemException::notFound('request_not_found', 'No such download request.');

        return $this->view($this->advance($row));
    }

    /**
     * An open request of the caller for the same movie is returned as it is; otherwise a new one is queued and the movie's link search started.
     *
     * @return array{request: array<string, mixed>, created: bool}
     */
    public function ask(string $requester, string $movieCode): array
    {
        $movieId = $this->movies->downloadSearchIdByCode($movieCode);
        $code = $movieId === null ? null : $this->movies->downloadSearchDisplayCode($movieId);

        if ($movieId === null || $code === null) {
            throw ProblemException::notFound('movie_not_found', "No movie {$movieCode}.");
        }

        $open = $this->repository->openRequest($requester, $code);

        if ($open !== null) {
            return ['request' => $this->view($this->advance($open)), 'created' => false];
        }

        $row = $this->repository->createRequest($requester, $code);

        if ($this->search->start($code, $requester)) {
            $this->repository->updateRequest((int) $row->id, ['attempts' => 1, 'last_attempt_at' => now()]);
            $row = $this->repository->requestOf((int) $row->id, $requester) ?? $row;
        }

        return ['request' => $this->view($this->advance($row)), 'created' => true];
    }

    public function cancel(int $id, string $requester): void
    {
        $this->repository->cancelRequest($id, $requester);
    }

    /** An open request takes over the state of its movie's link search. */
    private function advance(object $row): object
    {
        if (! in_array($row->state, ['queued', 'searching'], true)) {
            return $row;
        }

        $movieId = $this->movies->downloadSearchIdByCode((string) $row->movie_code);
        $search = $movieId === null ? null : $this->repository->linkSearchOfMovie($movieId);
        $state = $search === null ? null : (self::SEARCH_TO_REQUEST[$search->state] ?? null);

        if ($search === null || $state === null || ($state === $row->state && (int) $row->found_count === $search->found_count)) {
            return $row;
        }

        $changes = ['state' => $state, 'found_count' => $search->found_count];
        $this->repository->updateRequest((int) $row->id, $changes);

        return (object) ($changes + (array) $row);
    }

    /** @return array<string, mixed> */
    private function view(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'movie_code' => (string) $row->movie_code,
            'state' => (string) $row->state,
            'found_count' => (int) $row->found_count,
            'attempts' => (int) $row->attempts,
            'created_at' => Stamp::iso($row->created_at),
            'last_attempt_at' => Stamp::iso($row->last_attempt_at),
        ];
    }
}
