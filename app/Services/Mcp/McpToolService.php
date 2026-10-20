<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Exceptions\InvalidFilterException;
use App\Http\Resources\MovieResource;
use App\Http\Resources\MovieSummaryResource;
use App\Http\Resources\PerformerResource;
use App\Http\Resources\PerformerSummaryResource;
use App\Models\Movie;
use App\Models\Performer;
use App\Repositories\PerformerRepository;
use App\Services\Crawl\CrawlStatusService;
use App\Services\Health\SystemStatusService;
use App\Services\Miss\ConsumerMissReporter;
use App\Services\Movies\MovieLookupService;
use App\Services\Search\CursorCodec;
use App\Services\Search\MovieSearchService;
use App\Services\Search\PerformerSearchService;
use App\Support\Code\NormalizedCode;
use DateTimeImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class McpToolService
{
    public function __construct(
        private readonly MovieLookupService $movieLookup,
        private readonly MovieSearchService $movieSearch,
        private readonly PerformerSearchService $performerSearch,
        private readonly PerformerRepository $performers,
        private readonly CursorCodec $cursorCodec,
        private readonly ConsumerMissReporter $misses,
        private readonly CrawlStatusService $crawlStatusService,
        private readonly SystemStatusService $systemStatusService,
    ) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return [
            $this->lookupMoviesDefinition(),
            $this->getMovieDefinition(),
            $this->searchDefinition(),
            $this->lookupPerformersDefinition(),
            $this->getCountsDefinition(),
            $this->crawlStatusDefinition(),
            $this->systemStatusDefinition(),
            $this->getPerformerDefinition(),
        ];
    }

    /** @return array<string, mixed> */
    private function lookupMoviesDefinition(): array
    {
        return [
            'name' => 'lookup_movies',
            'description' => 'Return a filtered, sorted and cursor-paginated list of movies.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Keyword across code, title and performer names.'],
                    'code' => ['type' => 'string', 'description' => 'Movie code or partial code.'],
                    'genre' => ['type' => 'string'],
                    'actress' => ['type' => 'string'],
                    'maker' => ['type' => 'string'],
                    'series' => ['type' => 'string'],
                    'label' => ['type' => 'string'],
                    'released_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'released_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                    'runtime_min' => ['type' => 'integer'],
                    'runtime_max' => ['type' => 'integer'],
                    'censored' => [
                        'oneOf' => [
                            ['type' => 'boolean'],
                            ['type' => 'integer', 'enum' => [0, 1]],
                            ['type' => 'string', 'enum' => ['0', '1', 'censored', 'uncensored']],
                        ],
                    ],
                    'sort' => ['type' => 'string', 'enum' => CursorCodec::SORTS, 'default' => 'relevance'],
                    'cursor' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                    'count_only' => ['type' => 'boolean', 'description' => 'Return only the total count instead of the item list.'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function getMovieDefinition(): array
    {
        return [
            'name' => 'get_movie',
            'description' => 'Return one complete movie by normalized code, including media URL references.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'code' => ['type' => 'string', 'description' => 'DVD/amateur code, for example STARS-456.'],
                ],
                'required' => ['code'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function searchDefinition(): array
    {
        return [
            'name' => 'search',
            'description' => 'Semantic natural-language search across movies and performers.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Natural-language description of a movie or performer.'],
                    'entity' => ['type' => 'string', 'enum' => ['movie', 'performer', 'all'], 'default' => 'movie', 'description' => 'Search movies, performers, or both.'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                ],
                'required' => ['q'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function lookupPerformersDefinition(): array
    {
        return [
            'name' => 'lookup_performers',
            'description' => 'Return a filtered, sorted and cursor-paginated list of performers.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Name, alias or bio text.'],
                    'age_min' => ['type' => 'integer'],
                    'age_max' => ['type' => 'integer'],
                    'height_min' => ['type' => 'integer'],
                    'height_max' => ['type' => 'integer'],
                    'bust_min' => ['type' => 'integer'],
                    'bust_max' => ['type' => 'integer'],
                    'waist_min' => ['type' => 'integer'],
                    'waist_max' => ['type' => 'integer'],
                    'hip_min' => ['type' => 'integer'],
                    'hip_max' => ['type' => 'integer'],
                    'cup' => ['type' => 'string'],
                    'blood_type' => ['type' => 'string'],
                    'location' => ['type' => 'string'],
                    'sort' => ['type' => 'string', 'enum' => PerformerRepository::SORTS, 'default' => 'name'],
                    'cursor' => ['type' => 'string'],
                    'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 10],
                    'count_only' => ['type' => 'boolean', 'description' => 'Return only the total count instead of the item list.'],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function getCountsDefinition(): array
    {
        return [
            'name' => 'get_counts',
            'description' => 'Return the total number of movies and performers in the catalog.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function crawlStatusDefinition(): array
    {
        return [
            'name' => 'crawl_status',
            'description' => 'Return crawl pipeline status: catalog counts, queue depth, per-source health and per-instance worker liveness.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function systemStatusDefinition(): array
    {
        return [
            'name' => 'system_status',
            'description' => 'Return health of the backing services: database, Elasticsearch, embedder, activity log and observability.',
            'inputSchema' => ['type' => 'object', 'properties' => []],
        ];
    }

    /** @return array<string, mixed> */
    private function getPerformerDefinition(): array
    {
        return [
            'name' => 'get_performer',
            'description' => 'Return one complete performer profile by numeric id or UUID.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string', 'description' => 'Performer numeric id or UUID.'],
                ],
                'required' => ['id'],
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    public function call(string $name, array $arguments): array
    {
        try {
            return match ($name) {
                'lookup_movies' => $this->lookupMovies($arguments),
                'get_movie' => $this->getMovie($arguments),
                'search' => $this->search($arguments),
                'lookup_performers' => $this->lookupPerformers($arguments),
                'get_performer' => $this->getPerformer($arguments),
                'get_counts' => $this->getCounts($arguments),
                'crawl_status' => $this->crawlStatus(),
                'system_status' => $this->systemStatus(),
                default => ['error' => 'unknown_tool'],
            };
        } catch (InvalidFilterException $exception) {
            return ['error' => 'invalid_filter', 'message' => $exception->getMessage()];
        }
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function lookupMovies(array $arguments): array
    {
        $filters = $this->movieFilters($arguments);
        $sort = $this->stringArgument($arguments, 'sort') ?? 'relevance';
        $this->assertAllowedSort($sort, CursorCodec::SORTS);

        if ($this->booleanArgument($arguments, 'count_only')) {
            return ['count' => $this->movieSearch->count($filters)];
        }

        $perPage = $this->perPage($arguments);
        $cursorValue = $this->stringArgument($arguments, 'cursor');
        $cursor = $cursorValue !== null ? $this->cursorCodec->decode($cursorValue, CursorCodec::SORTS) : null;

        if ($cursor !== null && $cursor['sort'] !== $sort) {
            throw new InvalidFilterException('The sort parameter cannot change between pages.');
        }

        $result = $this->movieSearch->search($filters, $cursor['sort'] ?? $sort, $cursor, $perPage);
        $nextCursor = $result['last_id'] === null
            ? null
            : $this->cursorCodec->encode($result['sort'], $result['last_sort'], $result['last_id'], CursorCodec::SORTS);
        $request = Request::create('/');

        return [
            'items' => $result['items']->map(fn(Movie $movie): array => (new MovieSummaryResource($movie))->toArray($request))->values()->all(),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'sort' => $result['sort'],
                'has_more' => $result['has_more'],
                'cursor' => $cursorValue,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function getMovie(array $arguments): array
    {
        $code = $this->requiredStringArgument($arguments, 'code');

        try {
            NormalizedCode::from($code);
        } catch (InvalidArgumentException $exception) {
            return ['error' => 'invalid_code', 'message' => $exception->getMessage()];
        }

        $movie = $this->movieLookup->findByCode($code);
        if (! $movie instanceof Movie) {
            $this->misses->report('movie', $code, ['endpoint' => 'mcp.get_movie']);

            return ['error' => 'movie_not_found', 'code' => $code];
        }

        return (new MovieResource($movie))->toArray(Request::create('/'));
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function search(array $arguments): array
    {
        $q = $this->requiredStringArgument($arguments, 'q');
        $perPage = $this->perPage($arguments);
        $entity = $this->searchEntity($arguments);

        if ($entity === 'movie') {
            return ['query' => $q] + $this->movieSemanticPayload($q, $perPage);
        }

        if ($entity === 'performer') {
            return ['query' => $q] + $this->performerSemanticPayload($q, $perPage);
        }

        return [
            'query' => $q,
            'movies' => $this->movieSemanticPayload($q, $perPage),
            'performers' => $this->performerSemanticPayload($q, $perPage),
        ];
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>} */
    private function movieSemanticPayload(string $query, int $perPage): array
    {
        $result = $this->movieSearch->semanticSearch($query, $perPage);
        $request = Request::create('/');

        return [
            'items' => $result['items']->map(fn(Movie $movie): array => (new MovieSummaryResource($movie))->toArray($request))->values()->all(),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'has_more' => $result['has_more'],
            ],
        ];
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>} */
    private function performerSemanticPayload(string $query, int $perPage): array
    {
        $result = $this->performerSearch->semanticSearch($query, $perPage);
        $request = Request::create('/');

        return [
            'items' => $result['items']->map(fn(Performer $performer): array => (new PerformerSummaryResource($performer))->toArray($request))->values()->all(),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'has_more' => $result['has_more'],
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    private function searchEntity(array $arguments): string
    {
        $entity = $arguments['entity'] ?? 'movie';
        if (! is_string($entity) || ! in_array($entity, ['movie', 'performer', 'all'], true)) {
            throw new InvalidFilterException('entity must be movie, performer or all.');
        }

        return $entity;
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function lookupPerformers(array $arguments): array
    {
        $filters = $this->performerFilters($arguments);
        $sort = $this->stringArgument($arguments, 'sort') ?? 'name';
        $this->assertAllowedSort($sort, PerformerRepository::SORTS);

        if ($this->booleanArgument($arguments, 'count_only')) {
            return ['count' => $this->performers->countFiltered($filters)];
        }

        $perPage = $this->perPage($arguments);
        $cursorValue = $this->stringArgument($arguments, 'cursor');
        $cursor = $cursorValue !== null
            ? $this->cursorCodec->decode($cursorValue, PerformerRepository::SORTS)
            : null;

        if ($cursor !== null && $cursor['sort'] !== $sort) {
            throw new InvalidFilterException('The sort parameter cannot change between pages.');
        }

        $result = $this->performers->search($filters, $cursor['sort'] ?? $sort, $cursor, $perPage);
        $nextCursor = $result['last_id'] === null
            ? null
            : $this->cursorCodec->encode($result['sort'], $result['last_sort'], $result['last_id'], PerformerRepository::SORTS);
        $request = Request::create('/');

        return [
            'items' => $result['items']->map(fn(Performer $performer): array => (new PerformerSummaryResource($performer))->toArray($request))->values()->all(),
            'pagination' => [
                'total' => $result['total'],
                'per_page' => $perPage,
                'sort' => $result['sort'],
                'has_more' => $result['has_more'],
                'cursor' => $cursorValue,
                'next_cursor' => $nextCursor,
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function getPerformer(array $arguments): array
    {
        $id = $this->requiredStringArgument($arguments, 'id');
        $performer = $this->performers->findForDisplay($id);

        if (! $performer instanceof Performer) {
            $this->misses->report('performer', $id, ['endpoint' => 'mcp.get_performer']);

            return ['error' => 'performer_not_found', 'id' => $id];
        }

        return (new PerformerResource($performer))->toArray(Request::create('/'));
    }

    /** @return array<string, mixed> */
    private function getCounts(array $arguments): array
    {
        unset($arguments);

        return [
            'movies' => (int) Movie::query()->count(),
            'performers' => (int) Performer::query()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function crawlStatus(): array
    {
        return $this->crawlStatusService->status();
    }

    /** @return array<string, mixed> */
    private function systemStatus(): array
    {
        return $this->systemStatusService->status();
    }

    /** @param array<string, mixed> $arguments */
    private function booleanArgument(array $arguments, string $key): bool
    {
        if (! array_key_exists($key, $arguments)) {
            return false;
        }

        $value = $arguments[$key];

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }

        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new InvalidFilterException("{$key} must be a boolean.");
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function movieFilters(array $arguments): array
    {
        $filters = [];
        foreach ([
            'q', 'code', 'genre', 'actress', 'maker', 'series', 'label',
            'released_from', 'released_to',
        ] as $key) {
            if (array_key_exists($key, $arguments)) {
                $value = $this->stringArgument($arguments, $key);
                if ($value !== null) {
                    if (in_array($key, ['released_from', 'released_to'], true)) {
                        $this->assertDate($key, $value);
                    }

                    $filters[$key] = $value;
                }
            }
        }

        foreach (['runtime_min', 'runtime_max'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $filters[$key] = $this->integerArgument($arguments, $key);
            }
        }

        if (array_key_exists('censored', $arguments)) {
            $filters['censored'] = $arguments['censored'];
        }

        if (array_key_exists('censored', $filters)) {
            $filters['censored'] = $this->censoredValue($filters['censored']);
        }

        return $filters;
    }

    /** @param array<string, mixed> $arguments */
    /** @return array<string, mixed> */
    private function performerFilters(array $arguments): array
    {
        $filters = [];
        foreach (['q', 'cup', 'blood_type', 'location'] as $key) {
            if (array_key_exists($key, $arguments)) {
                $value = $this->stringArgument($arguments, $key);
                if ($value !== null) {
                    $filters[$key] = $value;
                }
            }
        }

        foreach ([
            'age_min', 'age_max', 'height_min', 'height_max', 'bust_min', 'bust_max',
            'waist_min', 'waist_max', 'hip_min', 'hip_max',
        ] as $key) {
            if (array_key_exists($key, $arguments)) {
                $filters[$key] = $this->integerArgument($arguments, $key);
            }
        }

        return $filters;
    }

    private function censoredValue(mixed $value): int
    {
        if ($value === true || $value === 1 || $value === '1' || $value === 'censored') {
            return 1;
        }

        if ($value === false || $value === 0 || $value === '0' || $value === 'uncensored') {
            return 0;
        }

        throw new InvalidFilterException('censored must be censored, uncensored, 0 or 1.');
    }

    /** @param list<string> $allowed */
    private function assertAllowedSort(string $sort, array $allowed): void
    {
        if (! in_array($sort, $allowed, true)) {
            throw new InvalidFilterException('Unsupported sort.');
        }
    }

    /** @param array<string, mixed> $arguments */
    private function perPage(array $arguments): int
    {
        $value = array_key_exists('per_page', $arguments) ? $arguments['per_page'] : 10;
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidFilterException('per_page must be an integer.');
        }

        $perPage = (int) $value;
        if ($perPage < 1 || $perPage > 100) {
            throw new InvalidFilterException('per_page must be between 1 and 100.');
        }

        return $perPage;
    }

    /** @param array<string, mixed> $arguments */
    private function integerArgument(array $arguments, string $key): int
    {
        $value = $arguments[$key] ?? null;
        if (! is_int($value)) {
            throw new InvalidFilterException("{$key} must be an integer.");
        }

        return $value;
    }

    private function assertDate(string $key, string $value): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false
            || $date->format('Y-m-d') !== $value
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new InvalidFilterException("{$key} must be a valid date in YYYY-MM-DD format.");
        }
    }

    /** @param array<string, mixed> $arguments */
    private function requiredStringArgument(array $arguments, string $key): string
    {
        $value = $this->stringArgument($arguments, $key);
        if ($value === null) {
            throw new InvalidFilterException("{$key} is required.");
        }

        return $value;
    }

    /** @param array<string, mixed> $arguments */
    private function stringArgument(array $arguments, string $key): ?string
    {
        if (! array_key_exists($key, $arguments)) {
            return null;
        }

        $value = $arguments[$key];
        if (! is_string($value)) {
            throw new InvalidFilterException("{$key} must be a string.");
        }

        return trim($value) !== '' ? trim($value) : null;
    }
}
