<?php

declare(strict_types=1);

namespace App\Services\Persist;

use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\MoviePerformer;
use App\Services\Identity\PerformerIdentityService;
use App\Support\Code\NormalizedCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;

/**
 * Attaches eporner (and similar) gallery photo URLs to a matching movie,
 * and upserts thin performer rows from gallery performer name labels.
 */
final class GalleryPersister
{
    public function __construct(private readonly PerformerIdentityService $identity) {}

    public function persist(string $sourceSlug, string $url, GalleryDto $gallery): void
    {
        $crawledAt = CarbonImmutable::now();

        DB::transaction(function () use ($sourceSlug, $url, $gallery, $crawledAt): void {
            $movie = $this->resolveMovie($gallery->title, $gallery->metadata);

            if ($movie instanceof Movie) {
                foreach ($gallery->photos as $photo) {
                    $imageUrl = $this->photoUrl($photo);
                    if ($imageUrl === null) {
                        continue;
                    }

                    MovieMedia::query()->updateOrCreate(
                        [
                            'movie_id' => (int) $movie->id,
                            'kind' => MovieMedia::KIND_GALLERY,
                            'url' => $imageUrl,
                        ],
                        [
                            'source_slug' => $sourceSlug,
                            'meta' => $this->galleryPhotoMeta($gallery, $url, $photo),
                            'crawled_at' => $crawledAt,
                        ],
                    );
                }
            }

            foreach ($gallery->performers as $name) {
                $trimmed = trim($name);
                if ($trimmed === '') {
                    continue;
                }

                $externalId = Str::slug($trimmed);
                if ($externalId === '') {
                    $externalId = 'name:' . mb_strtolower($trimmed);
                }

                $performer = Performer::query()->firstOrCreate(
                    ['source_slug' => $sourceSlug, 'external_id' => $externalId],
                    [
                        'name_romaji' => $trimmed,
                        'attrs' => [],
                        'needs_review' => false,
                        'first_seen_at' => $crawledAt,
                        'updated_at' => $crawledAt,
                        'crawled_at' => $crawledAt,
                    ],
                );

                $source = PerformerSource::query()->firstOrNew([
                    'site_slug' => $sourceSlug,
                    'external_id' => $externalId,
                ]);
                $source->fill([
                    'performer_id' => $performer->canonical_performer_id ?? $performer->id,
                    'source_performer_id' => $performer->id,
                    'mongo_id' => $source->mongo_id ?: ('local:' . $performer->id),
                    'source_url' => $url,
                    'name_romaji' => $trimmed,
                    'removed_at' => null,
                    'crawled_at' => $crawledAt,
                ]);
                $source->save();

                $canonicalId = $this->identity->reconcileAndSync($source);

                foreach ($gallery->photos as $photo) {
                    $imageUrl = $this->photoUrl($photo);
                    if ($imageUrl === null) {
                        continue;
                    }

                    PerformerMedia::query()->updateOrCreate(
                        [
                            'performer_id' => $canonicalId,
                            'source_performer_id' => (int) $performer->id,
                            'kind' => PerformerMedia::KIND_GALLERY,
                            'url' => $imageUrl,
                        ],
                        [
                            'source_slug' => $sourceSlug,
                            'meta' => $this->galleryPhotoMeta($gallery, $url, $photo),
                            'crawled_at' => $crawledAt,
                        ],
                    );
                }

                if ($movie instanceof Movie) {
                    // Link the canonical record for the API and the source record so a later split can restore it.
                    foreach (array_unique([$canonicalId, (int) $performer->id]) as $linkedId) {
                        MoviePerformer::query()->upsert(
                            [[
                                'movie_id' => (int) $movie->id,
                                'performer_id' => $linkedId,
                                'source_slug' => $sourceSlug,
                                'crawled_at' => $crawledAt,
                            ]],
                            ['movie_id', 'performer_id'],
                            ['source_slug', 'crawled_at'],
                        );
                    }
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveMovie(?string $title, array $metadata): ?Movie
    {
        $movieCode = $metadata['movie_code'] ?? null;
        if (is_string($movieCode)) {
            $movie = $this->resolveMovieCode($movieCode);
            if ($movie instanceof Movie) {
                return $movie;
            }
        }

        if ($title === null || trim($title) === '') {
            return null;
        }

        if (preg_match('/\b([A-Z]{2,10})-?(\d{2,6})\b/i', $title, $m) !== 1) {
            return null;
        }

        return $this->resolveMovieCode($m[1] . '-' . $m[2]);
    }

    private function resolveMovieCode(string $rawCode): ?Movie
    {
        try {
            $normalized = NormalizedCode::from($rawCode);
        } catch (InvalidArgumentException) {
            return null;
        }

        $movieId = MovieCode::query()
            ->where('code_normalized', $normalized->value())
            ->value('movie_id');

        if (! is_numeric($movieId)) {
            $movieId = Movie::query()->where('code_normalized', $normalized->value())->value('id');
        }

        if (! is_numeric($movieId)) {
            return null;
        }

        $movie = Movie::query()->find((int) $movieId);

        return $movie instanceof Movie ? $movie : null;
    }

    private function photoUrl(PhotoDto $photo): ?string
    {
        foreach ([$photo->imageUrl, $photo->url, $photo->thumbnailUrl] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function galleryPhotoMeta(GalleryDto $gallery, string $url, PhotoDto $photo): array
    {
        return [
            'gallery_id' => $gallery->externalId,
            'gallery_title' => $gallery->title,
            'gallery_url' => $url,
            'thumbnail_url' => $photo->thumbnailUrl,
            'position' => $photo->position,
        ];
    }
}
