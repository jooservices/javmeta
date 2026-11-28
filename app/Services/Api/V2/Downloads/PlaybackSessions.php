<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Downloads;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\LibraryRepository;
use App\Repositories\MovieRepository;
use Illuminate\Support\Str;

/** Hands out the HLS addresses the crawler found for a movie, with a 15-minute expiry. */
final class PlaybackSessions
{
    public function __construct(private readonly LibraryRepository $repository, private readonly MovieRepository $movies) {}

    /** @return array<string, mixed> */
    public function start(string $movieCode): array
    {
        $movieId = $this->movies->downloadSearchIdByCode($movieCode);
        $code = $movieId === null ? null : $this->movies->downloadSearchDisplayCode($movieId);

        if ($movieId === null || $code === null) {
            throw ProblemException::notFound('movie_not_found', "No movie {$movieCode}.");
        }

        return [
            'id' => (string) Str::uuid(),
            'movie_code' => $code,
            'hls' => array_map(static fn(object $row): array => ['url' => (string) $row->url, 'quality' => self::quality($row->meta)], $this->repository->hlsOf($movieId)),
            'expires_at' => now()->addMinutes(15)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function quality(mixed $meta): ?string
    {
        $decoded = is_string($meta) ? json_decode($meta, true) : $meta;
        $quality = is_array($decoded) ? ($decoded['quality'] ?? null) : null;

        return is_string($quality) ? $quality : null;
    }
}
