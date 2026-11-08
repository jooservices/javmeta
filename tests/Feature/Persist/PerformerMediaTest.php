<?php

declare(strict_types=1);

namespace Tests\Feature\Persist;

use App\Models\Movie;
use App\Models\MovieCode;
use App\Models\MovieMedia;
use App\Models\Performer;
use App\Models\PerformerMedia;
use App\Models\PerformerSource;
use App\Services\Persist\GalleryPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use JOOservices\CrawlerX\Dto\Entity\GalleryDto;
use JOOservices\CrawlerX\Dto\Entity\PhotoDto;
use Tests\TestCase;

final class PerformerMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_url_reference_media_for_a_performer(): void
    {
        $performer = Performer::factory()->create();

        $media = PerformerMedia::factory()->for($performer)->create();

        $this->assertSame((int) $performer->id, (int) $media->performer_id);
        $this->assertNotSame('', $media->url);
        $this->assertNull($media->meta);
    }

    public function test_gallery_photos_are_persisted_for_each_performer_with_source_meta_and_idempotence(): void
    {
        $sourceSlug = fake()->unique()->slug(2);
        $movie = Movie::factory()->create([
            'display_code' => 'ABC-123',
            'code_normalized' => 'ABC123',
        ]);
        MovieCode::factory()->create([
            'movie_id' => $movie->id,
            'code' => 'ABC-123',
            'code_normalized' => 'ABC123',
            'source_slug' => $sourceSlug,
        ]);

        $galleryUrl = fake()->url();
        $galleryId = fake()->unique()->bothify('gallery-????');
        $galleryTitle = fake()->sentence(4);
        $performerNames = [fake()->name(), fake()->name()];
        $photos = [
            new PhotoDto(
                id: fake()->uuid(),
                imageUrl: fake()->url(),
                thumbnailUrl: fake()->url(),
                position: 1,
            ),
            new PhotoDto(
                id: fake()->uuid(),
                imageUrl: fake()->url(),
                thumbnailUrl: fake()->url(),
                position: 2,
            ),
        ];
        $gallery = new GalleryDto(
            externalId: $galleryId,
            title: $galleryTitle,
            performers: $performerNames,
            photos: $photos,
            metadata: ['movie_code' => 'ABC-123'],
        );

        $persister = app(GalleryPersister::class);
        $persister->persist($sourceSlug, $galleryUrl, $gallery);
        $persister->persist($sourceSlug, $galleryUrl, $gallery);

        $performers = Performer::query()->where('source_slug', $sourceSlug)->get();
        $this->assertCount(count($performerNames), $performers);
        $this->assertSame(count($performerNames), $movie->refresh()->performers()->count());
        $this->assertSame(count($photos), MovieMedia::query()->where('movie_id', $movie->id)->count());
        $this->assertSame(count($performerNames) * count($photos), PerformerMedia::query()->count());

        foreach ($performerNames as $performerName) {
            $performer = $performers->firstWhere('external_id', Str::slug($performerName));
            $this->assertNotNull($performer);
            $this->assertTrue(PerformerSource::query()
                ->where('source_performer_id', $performer->id)
                ->where('site_slug', $sourceSlug)
                ->exists());
            $this->assertSame(count($photos), $performer->media()->where('kind', PerformerMedia::KIND_GALLERY)->count());
            $this->assertTrue($performer->movies()->whereKey($movie->id)->exists());
        }

        $media = PerformerMedia::query()->where('kind', PerformerMedia::KIND_GALLERY)->firstOrFail();
        $this->assertSame($sourceSlug, $media->source_slug);
        $this->assertSame($galleryId, $media->meta['gallery_id']);
        $this->assertSame($galleryTitle, $media->meta['gallery_title']);
        $this->assertSame($galleryUrl, $media->meta['gallery_url']);
        $this->assertSame($photos[0]->thumbnailUrl, $media->meta['thumbnail_url']);
        $this->assertSame($photos[0]->position, $media->meta['position']);

        $movieMedia = MovieMedia::query()->where('movie_id', $movie->id)->firstOrFail();
        $this->assertSame($galleryId, $movieMedia->meta['gallery_id']);
        $this->assertSame($galleryTitle, $movieMedia->meta['gallery_title']);
    }
}
