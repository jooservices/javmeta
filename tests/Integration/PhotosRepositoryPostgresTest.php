<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Data\Photos\GalleryId;
use App\Data\Photos\PhotosGalleryQuery;
use App\Data\Photos\PhotosGroupQuery;
use App\Models\Movie;
use App\Models\MoviePerformer;
use App\Models\Performer;
use App\Repositories\PhotosRepository;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

final class PhotosRepositoryPostgresTest extends BaseTestCase
{
    use DatabaseMigrations;

    public function test_source_filter_is_shared_by_group_counts_covers_and_movie_performer_relation(): void
    {
        $selected = Performer::factory()->create(['name_romaji' => 'Selected performer']);
        $other = Performer::factory()->create(['name_romaji' => 'Other performer']);
        $sharedMovie = Movie::factory()->create();
        $otherMovie = Movie::factory()->create();
        MoviePerformer::factory()->create(['movie_id' => $sharedMovie->id, 'performer_id' => $selected->id]);
        MoviePerformer::factory()->create(['movie_id' => $sharedMovie->id, 'performer_id' => $other->id]);
        MoviePerformer::factory()->create(['movie_id' => $otherMovie->id, 'performer_id' => $other->id]);

        $this->addPerformerGallery($selected, 'source-a', 'performer-gallery', 'Performer gallery', 'performer-a.jpg');
        $this->addPerformerGallery($selected, 'source-b', 'performer-gallery-b', 'Performer gallery B', 'performer-b.jpg');
        $this->addMovieGallery($sharedMovie, 'source-a', 'shared-gallery-a', 'Shared movie A', 'shared-a.jpg');
        $this->addMovieGallery($sharedMovie, 'source-b', 'shared-gallery-b', 'Shared movie B', 'shared-b.jpg');
        $this->addMovieGallery($otherMovie, 'source-a', 'other-gallery-a', 'Other movie A', 'other-a.jpg');

        $repository = app(PhotosRepository::class);
        $performers = $repository->groups(new PhotosGroupQuery('performer', '', 'source-a', $selected->id, null, 1, 20));
        $movies = $repository->groups(new PhotosGroupQuery('movie', '', 'source-a', $selected->id, null, 1, 20));
        $galleries = $repository->groups(new PhotosGroupQuery('gallery', '', 'source-a', $selected->id, null, 1, 20));

        self::assertSame(1, $performers['total']);
        self::assertSame(1, $performers['items'][0]['photo_count']);
        self::assertSame('performer-a.jpg', $performers['items'][0]['cover_url']);
        self::assertSame(['performer-a.jpg'], $performers['items'][0]['preview_urls']);

        self::assertSame(1, $movies['total']);
        self::assertSame((int) $sharedMovie->id, $movies['items'][0]['id']);
        self::assertSame(1, $movies['items'][0]['photo_count']);
        self::assertSame('shared-a.jpg', $movies['items'][0]['cover_url']);
        self::assertSame(['shared-a.jpg'], $movies['items'][0]['preview_urls']);

        self::assertSame(2, $galleries['total']);
        self::assertEqualsCanonicalizing(['performer-a.jpg', 'shared-a.jpg'], array_column($galleries['items'], 'cover_url'));
    }

    public function test_movie_filters_constrain_performer_and_gallery_results(): void
    {
        $selected = Performer::factory()->create();
        $shared = Performer::factory()->create();
        $unrelated = Performer::factory()->create();
        $sharedMovie = Movie::factory()->create(['display_code' => 'J446A-001', 'code_normalized' => 'J446A001']);
        $otherMovie = Movie::factory()->create(['display_code' => 'J446A-002', 'code_normalized' => 'J446A002']);
        $secondSelectedMovie = Movie::factory()->create(['display_code' => 'J446A-003', 'code_normalized' => 'J446A003']);
        MoviePerformer::factory()->create(['movie_id' => $sharedMovie->id, 'performer_id' => $selected->id]);
        MoviePerformer::factory()->create(['movie_id' => $sharedMovie->id, 'performer_id' => $shared->id]);
        MoviePerformer::factory()->create(['movie_id' => $otherMovie->id, 'performer_id' => $unrelated->id]);
        MoviePerformer::factory()->create(['movie_id' => $secondSelectedMovie->id, 'performer_id' => $selected->id]);

        $this->addPerformerGallery($selected, 'source-a', 'selected-gallery', 'Selected', 'selected.jpg');
        $this->addPerformerGallery($shared, 'source-a', 'shared-gallery', 'Shared', 'shared.jpg');
        $this->addPerformerGallery($unrelated, 'source-a', 'unrelated-gallery', 'Unrelated', 'unrelated.jpg');
        $this->addMovieGallery($sharedMovie, 'source-a', 'shared-movie-gallery', 'Shared movie', 'shared-movie.jpg');
        $this->addMovieGallery($otherMovie, 'source-a', 'other-movie-gallery', 'Other movie', 'other-movie.jpg');
        $this->addMovieGallery($secondSelectedMovie, 'source-a', 'second-selected-movie-gallery', 'Second selected movie', 'second-selected-movie.jpg');

        $repository = app(PhotosRepository::class);
        $sharedPerformers = $repository->groups(new PhotosGroupQuery('performer', '', '', null, $sharedMovie->display_code, 1, 20));
        $selectedPerformer = $repository->groups(new PhotosGroupQuery('performer', '', '', $selected->id, $sharedMovie->display_code, 1, 20));
        $multipleMoviePerformer = $repository->groups(new PhotosGroupQuery('performer', '', '', $selected->id, $secondSelectedMovie->display_code, 1, 20));
        $unrelatedPerformer = $repository->groups(new PhotosGroupQuery('performer', '', '', $selected->id, $otherMovie->display_code, 1, 20));
        $unknownMoviePerformer = $repository->groups(new PhotosGroupQuery('performer', '', '', null, 'J446A-999', 1, 20));
        $relatedMovies = $repository->groups(new PhotosGroupQuery('movie', '', '', $selected->id, $sharedMovie->display_code, 1, 20));
        $unrelatedMovies = $repository->groups(new PhotosGroupQuery('movie', '', '', $unrelated->id, $sharedMovie->display_code, 1, 20));
        $sharedGalleries = $repository->groups(new PhotosGroupQuery('gallery', '', '', null, $sharedMovie->display_code, 1, 20));
        $selectedGalleries = $repository->groups(new PhotosGroupQuery('gallery', '', '', $selected->id, $sharedMovie->display_code, 1, 20));
        $unrelatedGalleries = $repository->groups(new PhotosGroupQuery('gallery', '', '', $selected->id, $otherMovie->display_code, 1, 20));
        $unknownMovieGalleries = $repository->groups(new PhotosGroupQuery('gallery', '', '', null, 'J446A-999', 1, 20));
        $selectedListing = $repository->galleries(new PhotosGalleryQuery($selected->id, $sharedMovie->display_code, '', '', 1, 20));
        $multipleMovieListing = $repository->galleries(new PhotosGalleryQuery($selected->id, $secondSelectedMovie->display_code, '', '', 1, 20));
        $unrelatedListing = $repository->galleries(new PhotosGalleryQuery($selected->id, $otherMovie->display_code, '', '', 1, 20));
        $unknownMovieListing = $repository->galleries(new PhotosGalleryQuery($selected->id, 'J446A-999', '', '', 1, 20));

        self::assertSame(2, $sharedPerformers['total']);
        self::assertEqualsCanonicalizing([(int) $selected->id, (int) $shared->id], array_column($sharedPerformers['items'], 'id'));
        self::assertSame([(int) $selected->id], array_column($selectedPerformer['items'], 'id'));
        self::assertSame([(int) $selected->id], array_column($multipleMoviePerformer['items'], 'id'));
        self::assertSame(['items' => [], 'total' => 0], $unrelatedPerformer);
        self::assertSame(['items' => [], 'total' => 0], $unknownMoviePerformer);
        self::assertSame([(int) $sharedMovie->id], array_column($relatedMovies['items'], 'id'));
        self::assertSame(['items' => [], 'total' => 0], $unrelatedMovies);

        self::assertSame(3, $sharedGalleries['total']);
        self::assertEqualsCanonicalizing(
            [(int) $selected->id, (int) $shared->id],
            array_values(array_filter(array_map(
                static fn(array $group): ?int => $group['performer']['id'] ?? null,
                $sharedGalleries['items'],
            ))),
        );
        self::assertSame(
            [(int) $sharedMovie->id],
            array_values(array_filter(array_map(
                static fn(array $group): ?int => $group['movie']['id'] ?? null,
                $sharedGalleries['items'],
            ))),
        );
        self::assertSame(2, $selectedGalleries['total']);
        self::assertSame(['items' => [], 'total' => 0], $unrelatedGalleries);
        self::assertSame(['items' => [], 'total' => 0], $unknownMovieGalleries);
        self::assertSame(1, $selectedListing['total']);
        self::assertSame('selected-gallery', $selectedListing['items'][0]['gallery_key']);
        self::assertSame(1, $multipleMovieListing['total']);
        self::assertSame('selected-gallery', $multipleMovieListing['items'][0]['gallery_key']);
        self::assertSame(['items' => [], 'total' => 0], $unrelatedListing);
        self::assertSame(['items' => [], 'total' => 0], $unknownMovieListing);
    }

    public function test_gallery_identity_counts_sources_separately_and_long_keys_resolve_from_compact_ids(): void
    {
        $performer = Performer::factory()->create();
        $this->addPerformerGallery($performer, 'source-a', 'duplicate-key', 'A', 'a.jpg');
        $this->addPerformerGallery($performer, 'source-b', 'duplicate-key', 'B', 'b.jpg');
        $longKey = str_repeat('x', 400);
        $this->addPerformerGallery($performer, 'source-c', $longKey, 'Long gallery', 'long.jpg');

        $repository = app(PhotosRepository::class);
        $groups = $repository->groups(new PhotosGroupQuery('performer', '', '', $performer->id, null, 1, 20));
        $galleryGroups = $repository->groups(new PhotosGroupQuery('gallery', '', '', $performer->id, null, 1, 20));
        $listing = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', '', 1, 20));

        self::assertSame(3, $groups['items'][0]['gallery_count']);
        self::assertSame(3, $galleryGroups['total']);
        self::assertSame(3, $listing['total']);

        $ids = array_column($listing['items'], 'id');
        self::assertCount(3, array_unique($ids));
        $longGallery = collect($listing['items'])->firstWhere('gallery_key', $longKey);
        self::assertIsArray($longGallery);
        self::assertLessThanOrEqual(512, strlen($longGallery['id']));
        self::assertNotNull(GalleryId::decode($longGallery['id'])->mediaId);
        $imagesRequest = $this->resolveRequest(\App\Http\Requests\Photos\PhotosGalleryImagesRequest::class, []);

        foreach ($listing['items'] as $gallery) {
            self::assertSame($gallery['id'], $imagesRequest->photosImagesQuery($gallery['id'])->galleryId);
            $images = $repository->galleryImages(GalleryId::decode($gallery['id']), 1, 20);
            self::assertSame(1, $images['total']);
            self::assertSame($gallery['source'], $images['items'][0]['source']);
            self::assertSame($gallery['cover_url'], $images['items'][0]['url']);
            if ($gallery['gallery_key'] === $longKey) {
                self::assertSame($longKey, $images['gallery']['gallery_key']);
            }
        }
    }

    public function test_empty_gallery_identifiers_use_consistent_url_fallbacks_for_listing_and_images(): void
    {
        $performer = Performer::factory()->create();
        $galleryUrl = 'https://source.test/galleries/fallback';
        $this->addPerformerGallery($performer, 'source-a', '', 'URL fallback', 'url-fallback-1.jpg', null, $galleryUrl);
        $this->addPerformerGallery($performer, 'source-a', '', 'URL fallback', 'url-fallback-2.jpg', null, $galleryUrl);
        $this->addPerformerGallery($performer, 'source-a', '', 'Image fallback', 'image-fallback.jpg', null, '');

        $repository = app(PhotosRepository::class);
        $performerGroups = $repository->groups(new PhotosGroupQuery('performer', '', '', $performer->id, null, 1, 20));
        $galleryGroups = $repository->groups(new PhotosGroupQuery('gallery', '', '', $performer->id, null, 1, 20));
        $listing = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', '', 1, 20));

        self::assertSame(3, $performerGroups['items'][0]['photo_count']);
        self::assertSame(2, $performerGroups['items'][0]['gallery_count']);
        self::assertSame(2, $galleryGroups['total']);
        self::assertSame(2, $listing['total']);

        $urlFallback = collect($listing['items'])->firstWhere('gallery_key', $galleryUrl);
        $imageFallback = collect($listing['items'])->firstWhere('gallery_key', 'image-fallback.jpg');
        self::assertIsArray($urlFallback);
        self::assertIsArray($imageFallback);
        self::assertSame(2, $urlFallback['photo_count']);
        self::assertSame(1, $imageFallback['photo_count']);

        foreach ([$urlFallback, $imageFallback] as $gallery) {
            $images = $repository->galleryImages(GalleryId::decode($gallery['id']), 1, 20);
            self::assertSame($gallery['gallery_key'], $images['gallery']['gallery_key']);
            self::assertSame($gallery['photo_count'], $images['total']);
            self::assertSame($gallery['cover_url'], $images['items'][0]['url']);
        }
    }

    public function test_gallery_title_search_selects_whole_galleries_and_tied_pages_are_stable(): void
    {
        $performer = Performer::factory()->create();
        $this->addPerformerGallery($performer, 'source-a', 'matching', 'Other title', 'other.jpg', 1);
        $this->addPerformerGallery($performer, 'source-a', 'matching', 'Matching title', 'matching.jpg', 2);
        $missingTitleId = $this->addPerformerGallery($performer, 'source-a', 'matching', 'Temporary title', 'missing-title.jpg', 3);
        $this->removeGalleryTitle('performer_media', $missingTitleId);
        $this->addPerformerGallery($performer, 'source-b', 'matching', 'Other title', 'other-source.jpg');

        $repository = app(PhotosRepository::class);
        $filtered = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', 'Matching', 1, 20));
        $unfiltered = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', '', 1, 20));
        $unmatched = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', 'No such title', 1, 20));
        self::assertSame(1, $filtered['total']);
        $galleryId = GalleryId::decode($filtered['items'][0]['id']);
        $imagePageOne = $repository->galleryImages($galleryId, 1, 2);
        $imagePageTwo = $repository->galleryImages($galleryId, 2, 2);

        self::assertSame(3, $filtered['items'][0]['photo_count']);
        self::assertSame(['other.jpg', 'matching.jpg', 'missing-title.jpg'], $filtered['items'][0]['preview_urls']);
        self::assertSame('other.jpg', $filtered['items'][0]['cover_url']);
        self::assertSame(3, $imagePageOne['total']);
        self::assertSame(3, $imagePageOne['gallery']['photo_count']);
        self::assertSame('other.jpg', $imagePageOne['gallery']['cover_url']);
        self::assertSame(['other.jpg', 'matching.jpg'], array_column($imagePageOne['items'], 'url'));
        self::assertSame(['missing-title.jpg'], array_column($imagePageTwo['items'], 'url'));
        self::assertSame(3, $imagePageTwo['total']);
        self::assertSame(2, $unfiltered['total']);
        self::assertSame(3, $unfiltered['items'][0]['photo_count']);
        self::assertSame(['items' => [], 'total' => 0], $unmatched);

        $tiePerformer = Performer::factory()->create();
        foreach (['delta', 'alpha', 'charlie', 'bravo'] as $key) {
            $this->addPerformerGallery($tiePerformer, 'source-a', $key, $key, $key . '.jpg');
        }
        $query = new PhotosGroupQuery('gallery', '', 'source-a', $tiePerformer->id, null, 1, 2);
        $first = $repository->groups($query);
        $second = $repository->groups(new PhotosGroupQuery('gallery', '', 'source-a', $tiePerformer->id, null, 2, 2));
        $repeatedFirst = $repository->groups($query);
        $firstIds = array_column($first['items'], 'id');
        $secondIds = array_column($second['items'], 'id');

        self::assertSame(4, $first['total']);
        self::assertSame($firstIds, array_column($repeatedFirst['items'], 'id'));
        self::assertSame([], array_values(array_intersect($firstIds, $secondIds)));
        self::assertCount(4, array_unique([...$firstIds, ...$secondIds]));

        $tiedListingPerformer = Performer::factory()->create();
        foreach (['delta', 'alpha', 'charlie', 'bravo'] as $key) {
            $this->addPerformerGallery($tiedListingPerformer, 'source-a', $key, $key, $key . '.jpg');
        }
        $listingFirstPage = $repository->galleries(new PhotosGalleryQuery($tiedListingPerformer->id, null, '', '', 1, 2));
        $listingSecondPage = $repository->galleries(new PhotosGalleryQuery($tiedListingPerformer->id, null, '', '', 2, 2));
        $listingRepeatedFirstPage = $repository->galleries(new PhotosGalleryQuery($tiedListingPerformer->id, null, '', '', 1, 2));
        $listingFirstIds = array_column($listingFirstPage['items'], 'id');
        $listingSecondIds = array_column($listingSecondPage['items'], 'id');

        self::assertSame(4, $listingFirstPage['total']);
        self::assertSame($listingFirstIds, array_column($listingRepeatedFirstPage['items'], 'id'));
        self::assertSame([], array_values(array_intersect($listingFirstIds, $listingSecondIds)));
        self::assertCount(4, array_unique([...$listingFirstIds, ...$listingSecondIds]));
    }

    public function test_group_gallery_and_preview_searches_treat_like_wildcards_as_literal_text(): void
    {
        $repository = app(PhotosRepository::class);
        $cases = [
            '%' => 'Percent',
            '_' => 'Underscore',
            '!' => 'Bang',
        ];

        foreach ($cases as $wildcard => $label) {
            $matchingPerformer = Performer::factory()->create(['name_romaji' => 'Performer ' . $wildcard . ' ' . $label]);
            $decoyPerformer = Performer::factory()->create(['name_romaji' => 'Performer X ' . $label]);
            $this->addPerformerGallery($matchingPerformer, 'source-a', $label . '-match', 'Album ' . $wildcard . ' ' . $label, $label . '-match.jpg');
            $this->addPerformerGallery($matchingPerformer, 'source-a', $label . '-decoy', 'Album X ' . $label, $label . '-decoy.jpg');
            $this->addPerformerGallery($decoyPerformer, 'source-a', $label . '-other', 'Other album', $label . '-other.jpg');

            $matchingMovie = Movie::factory()->create(['title_en' => 'Movie ' . $wildcard . ' ' . $label]);
            $decoyMovie = Movie::factory()->create(['title_en' => 'Movie X ' . $label]);
            $this->addMovieGallery($matchingMovie, 'source-a', $label . '-movie-match', 'Movie album ' . $wildcard . ' ' . $label, $label . '-movie-match.jpg');
            $this->addMovieGallery($matchingMovie, 'source-a', $label . '-movie-decoy', 'Movie album X ' . $label, $label . '-movie-decoy.jpg');
            $this->addMovieGallery($decoyMovie, 'source-a', $label . '-movie-other', 'Other movie album', $label . '-movie-other.jpg');

            $performers = $repository->groups(new PhotosGroupQuery('performer', $wildcard, '', null, null, 1, 20));
            $movies = $repository->groups(new PhotosGroupQuery('movie', $wildcard, '', null, null, 1, 20));
            $galleryGroups = $repository->groups(new PhotosGroupQuery('gallery', $wildcard, '', null, null, 1, 20));
            $performerGalleries = $repository->galleries(new PhotosGalleryQuery($matchingPerformer->id, null, '', $wildcard, 1, 20));
            $movieGalleries = $repository->galleries(new PhotosGalleryQuery(null, $matchingMovie->display_code, '', $wildcard, 1, 20));

            self::assertSame([(int) $matchingPerformer->id], array_column($performers['items'], 'id'));
            self::assertSame([(int) $matchingMovie->id], array_column($movies['items'], 'id'));
            self::assertSame(4, $galleryGroups['total']);
            self::assertCount(4, $galleryGroups['items']);
            self::assertSame(
                [(int) $matchingPerformer->id, (int) $matchingPerformer->id],
                array_values(array_filter(array_map(
                    static fn(array $group): ?int => $group['performer']['id'] ?? null,
                    $galleryGroups['items'],
                ))),
            );
            self::assertSame(
                [(int) $matchingMovie->id, (int) $matchingMovie->id],
                array_values(array_filter(array_map(
                    static fn(array $group): ?int => $group['movie']['id'] ?? null,
                    $galleryGroups['items'],
                ))),
            );
            self::assertSame(1, $performerGalleries['total']);
            self::assertSame([$label . '-match.jpg'], $performerGalleries['items'][0]['preview_urls']);
            self::assertSame(1, $movieGalleries['total']);
            self::assertSame([$label . '-movie-match.jpg'], $movieGalleries['items'][0]['preview_urls']);
        }
    }

    public function test_gallery_group_search_includes_kana_only_performer_names(): void
    {
        $performer = Performer::factory()->create([
            'name_romaji' => 'Unrelated performer',
            'name_kanji' => '別人',
            'name_kana' => 'かな検索',
        ]);
        $this->addPerformerGallery($performer, 'source-a', 'kana-gallery', 'Kana gallery', 'kana.jpg');

        $repository = app(PhotosRepository::class);
        $performers = $repository->groups(new PhotosGroupQuery('performer', 'かな検索', '', null, null, 1, 20));
        $galleries = $repository->groups(new PhotosGroupQuery('gallery', 'かな検索', '', null, null, 1, 20));

        self::assertSame([(int) $performer->id], array_column($performers['items'], 'id'));
        self::assertSame(1, $galleries['total']);
        self::assertSame((int) $performer->id, $galleries['items'][0]['performer']['id']);
        self::assertSame(['kana.jpg'], $galleries['items'][0]['preview_urls']);
    }

    public function test_gallery_group_page_batches_previews_and_owner_summaries(): void
    {
        $performers = [];
        $movies = [];
        for ($index = 1; $index <= 6; $index++) {
            $performer = Performer::factory()->create();
            $movie = Movie::factory()->create();
            $performers[] = $performer;
            $movies[] = $movie;
            $this->addPerformerGallery($performer, 'source-a', 'performer-' . $index, 'Performer ' . $index, 'performer-' . $index . '.jpg');
            $this->addMovieGallery($movie, 'source-a', 'movie-' . $index, 'Movie ' . $index, 'movie-' . $index . '.jpg');
        }

        $queryCount = 0;
        DB::listen(static function () use (&$queryCount): void {
            $queryCount++;
        });

        $result = app(PhotosRepository::class)->groups(new PhotosGroupQuery('gallery', '', '', null, null, 1, 20));

        self::assertSame(12, $result['total']);
        self::assertCount(12, $result['items']);
        self::assertLessThanOrEqual(8, $queryCount);
        foreach ($result['items'] as $item) {
            self::assertCount(1, $item['preview_urls']);
            self::assertSame($item['preview_urls'][0], $item['cover_url']);
            self::assertNotNull($item['performer'] ?? $item['movie']);
        }

        $queryCount = 0;
        $performerGroups = app(PhotosRepository::class)->groups(new PhotosGroupQuery('performer', '', '', null, null, 1, 20));
        self::assertCount(6, $performerGroups['items']);
        self::assertLessThanOrEqual(4, $queryCount);

        $queryCount = 0;
        $movieGroups = app(PhotosRepository::class)->groups(new PhotosGroupQuery('movie', '', '', null, null, 1, 20));
        self::assertCount(6, $movieGroups['items']);
        self::assertLessThanOrEqual(4, $queryCount);

        $queryCount = 0;
        $performerGalleries = app(PhotosRepository::class)->galleries(new PhotosGalleryQuery($performers[0]->id, null, '', '', 1, 20));
        self::assertSame(1, $performerGalleries['total']);
        self::assertLessThanOrEqual(4, $queryCount);

        $queryCount = 0;
        $movieGalleries = app(PhotosRepository::class)->galleries(new PhotosGalleryQuery(null, $movies[0]->display_code, '', '', 1, 20));
        self::assertSame(1, $movieGalleries['total']);
        self::assertLessThanOrEqual(5, $queryCount);
    }

    public function test_movie_gallery_listing_filters_source_and_paginates_complete_gallery_identities(): void
    {
        $movie = Movie::factory()->create();
        $this->addMovieGallery($movie, 'source-a', 'shared-key', 'Album A', 'movie-a1.jpg');
        $this->addMovieGallery($movie, 'source-a', 'shared-key', 'Album A', 'movie-a2.jpg');
        $this->addMovieGallery($movie, 'source-b', 'shared-key', 'Album B', 'movie-b.jpg');

        $repository = app(PhotosRepository::class);
        $all = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, '', '', 1, 1));
        $pageTwo = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, '', '', 2, 1));
        $sourceA = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, 'source-a', 'Album A', 1, 20));
        $pastEnd = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, '', '', 3, 1));

        self::assertSame(2, $all['total']);
        self::assertSame(2, $all['items'][0]['photo_count']);
        self::assertSame(1, $pageTwo['items'][0]['photo_count']);
        self::assertNotSame($all['items'][0]['id'], $pageTwo['items'][0]['id']);
        self::assertSame('source-a', $sourceA['items'][0]['source']);
        self::assertSame(2, $sourceA['items'][0]['photo_count']);
        self::assertSame(['movie-a1.jpg', 'movie-a2.jpg'], $sourceA['items'][0]['preview_urls']);
        self::assertSame(2, $pastEnd['total']);
        self::assertSame([], $pastEnd['items']);
    }

    public function test_movie_gallery_title_search_counts_and_previews_the_complete_gallery(): void
    {
        $movie = Movie::factory()->create();
        $this->addMovieGallery($movie, 'source-a', 'matching-movie', 'Other title', 'other-movie.jpg', 1);
        $this->addMovieGallery($movie, 'source-a', 'matching-movie', 'Matching title', 'matching-movie.jpg', 2);
        $missingTitleId = $this->addMovieGallery($movie, 'source-a', 'matching-movie', 'Temporary title', 'missing-title-movie.jpg', 3);
        $this->removeGalleryTitle('movie_media', $missingTitleId);
        $this->addMovieGallery($movie, 'source-b', 'matching-movie', 'Other title', 'other-source-movie.jpg');

        $repository = app(PhotosRepository::class);
        $filtered = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, '', 'Matching', 1, 20));
        $unmatched = $repository->galleries(new PhotosGalleryQuery(null, $movie->display_code, '', 'No such title', 1, 20));
        self::assertSame(1, $filtered['total']);
        self::assertSame(['items' => [], 'total' => 0], $unmatched);
        $galleryId = GalleryId::decode($filtered['items'][0]['id']);
        $imagePageOne = $repository->galleryImages($galleryId, 1, 2);
        $imagePageTwo = $repository->galleryImages($galleryId, 2, 2);

        self::assertSame(3, $filtered['items'][0]['photo_count']);
        self::assertSame(['other-movie.jpg', 'matching-movie.jpg', 'missing-title-movie.jpg'], $filtered['items'][0]['preview_urls']);
        self::assertSame('other-movie.jpg', $filtered['items'][0]['cover_url']);
        self::assertSame(3, $imagePageOne['total']);
        self::assertSame('other-movie.jpg', $imagePageOne['gallery']['cover_url']);
        self::assertSame(['other-movie.jpg', 'matching-movie.jpg'], array_column($imagePageOne['items'], 'url'));
        self::assertSame(['missing-title-movie.jpg'], array_column($imagePageTwo['items'], 'url'));
        self::assertSame(3, $imagePageTwo['total']);
    }

    public function test_image_pages_facets_and_position_conversion_handle_large_and_malformed_values(): void
    {
        $performer = Performer::factory()->create();
        $this->addPerformerGallery($performer, 'source-a', 'ordered', 'Ordered', 'position-32.jpg', 2_147_483_648);
        $this->addPerformerGallery($performer, 'source-a', 'ordered', 'Ordered', 'position-five.jpg', 5);
        $this->addPerformerGallery($performer, 'source-a', 'ordered', 'Ordered', 'position-bad.jpg', 'bad');
        $this->addPerformerGallery($performer, 'source-a', 'ordered', 'Ordered', 'position-overflow.jpg', '9223372036854775808');
        $this->addPerformerGallery($performer, 'source-a', 'ordered', 'Ordered', 'position-missing.jpg');

        $repository = app(PhotosRepository::class);
        $listing = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', '', 1, 20));
        $id = GalleryId::decode($listing['items'][0]['id']);
        $pageOne = $repository->galleryImages($id, 1, 2);
        $pageTwo = $repository->galleryImages($id, 2, 2);
        $all = $repository->galleryImages($id, 1, 20);
        $facets = $repository->facetValues('source', '', 1, 20, 0, 'count');
        $performerFacets = $repository->facetValues('performer', '', 1, 20, 0, 'count');
        $filteredSources = $repository->facetValues('source', 'source-a', 1, 20, 5, 'count');
        $hiddenSources = $repository->facetValues('source', 'source-a', 1, 20, 6, 'count');
        $filteredPerformers = $repository->facetValues('performer', (string) $performer->id, 1, 20, 5, 'count');
        $unmatchedPerformers = $repository->facetValues('performer', 'no-such-performer', 1, 20, 0, 'count');

        self::assertSame(5, $pageOne['total']);
        self::assertSame(['position-five.jpg', 'position-32.jpg'], array_column($pageOne['items'], 'url'));
        self::assertSame(['position-bad.jpg', 'position-overflow.jpg'], array_column($pageTwo['items'], 'url'));
        self::assertSame([5, 2_147_483_648], array_column($pageOne['items'], 'position'));
        self::assertNull($all['items'][2]['position']);
        self::assertNull($all['items'][3]['position']);
        self::assertNull($all['items'][4]['position']);
        self::assertSame(1, $facets['total']);
        self::assertSame(['name' => 'source-a', 'count' => 5], $facets['items'][0]);
        self::assertSame(1, $performerFacets['total']);
        self::assertSame((int) $performer->id, $performerFacets['items'][0]['id']);
        self::assertSame(['name' => 'source-a', 'count' => 5], $filteredSources['items'][0]);
        self::assertSame(0, $hiddenSources['total']);
        self::assertSame(1, $hiddenSources['hidden_below_min']);
        self::assertSame((int) $performer->id, $filteredPerformers['items'][0]['id']);
        self::assertSame(0, $unmatchedPerformers['total']);
    }

    public function test_each_photo_request_rejects_malformed_fractional_and_unsafe_pages(): void
    {
        foreach ($this->photoRequestTypes() as $requestClass) {
            foreach (['1garbage', '1.5', '46116860184273881', '9223372036854775808', str_repeat('9', 100)] as $page) {
                $this->assertRequestInvalid($requestClass, ['page' => $page]);
            }
        }
    }

    public function test_empty_photo_queries_return_bounded_empty_pages(): void
    {
        $performer = Performer::factory()->create();
        $repository = app(PhotosRepository::class);
        $groupPage = $repository->groups(new PhotosGroupQuery('performer', '', '', null, null, 2, 200));
        $galleryPage = $repository->galleries(new PhotosGalleryQuery($performer->id, null, '', '', 2, 200));
        $imagePage = $repository->galleryImages(new GalleryId('performer', (int) $performer->id, 'source-a', 'missing'), 2, 200);
        $facetPage = $repository->facetValues('source', '', 2, 200, 0, 'count');

        self::assertSame(['items' => [], 'total' => 0], $groupPage);
        self::assertSame(['items' => [], 'total' => 0], $galleryPage);
        self::assertSame(['gallery' => null, 'items' => [], 'total' => 0], $imagePage);
        self::assertSame(['items' => [], 'total' => 0, 'hidden_below_min' => 0], $facetPage);
    }

    public function test_each_photo_request_clamps_only_valid_negative_pages_to_the_first_page(): void
    {
        foreach ($this->photoRequestTypes() as $requestClass) {
            $request = $this->resolveRequest($requestClass, ['page' => '-3']);
            self::assertSame(1, $request->validated('page'));
        }
    }

    public function test_photo_owner_ids_and_page_sizes_are_validated_before_casting(): void
    {
        foreach ([
            \App\Http\Requests\Photos\PhotosGroupsRequest::class,
            \App\Http\Requests\Photos\PhotosGalleriesRequest::class,
        ] as $requestClass) {
            $this->assertRequestInvalid($requestClass, ['performer' => '1garbage']);
        }

        foreach ($this->photoRequestTypes() as $requestClass) {
            $this->assertRequestInvalid($requestClass, ['per_page' => '20garbage']);
        }
    }

    public function test_facet_counts_reject_malformed_fractional_negative_and_oversized_values(): void
    {
        foreach (['1garbage', '1.5', '-1', '1000001', '9223372036854775808'] as $minCount) {
            $this->assertRequestInvalid(\App\Http\Requests\Photos\PhotosFacetListRequest::class, ['min_count' => $minCount]);
        }
    }

    public function test_bounded_legacy_long_gallery_ids_are_accepted_and_oversized_ids_are_rejected(): void
    {
        $legacyId = (new GalleryId('performer', 1, 'source-a', str_repeat('x', 400)))->encode();
        $request = $this->resolveRequest(\App\Http\Requests\Photos\PhotosGalleryImagesRequest::class, []);
        self::assertSame($legacyId, $request->photosImagesQuery($legacyId)->galleryId);

        try {
            $request->photosImagesQuery(str_repeat('a', GalleryId::MAX_ENCODED_LENGTH + 1));
            self::fail('An oversized gallery ID should be rejected.');
        } catch (\App\Exceptions\InvalidFilterException) {
            self::assertTrue(true);
        }

        try {
            GalleryId::decode('not a valid gallery id');
            self::fail('A malformed gallery ID should be rejected.');
        } catch (\App\Exceptions\InvalidFilterException) {
            self::assertTrue(true);
        }
    }

    private function addPerformerGallery(Performer $performer, string $source, string $key, string $title, string $url, mixed $position = null, ?string $galleryUrl = null): int
    {
        $meta = [
            'gallery_id' => $key,
            'gallery_title' => $title,
            'position' => $position,
            'gallery_url' => $galleryUrl,
        ];

        return (int) DB::table('performer_media')->insertGetId([
            'performer_id' => $performer->id,
            'source_performer_id' => $performer->id,
            'kind' => 'gallery',
            'url' => $url,
            'meta' => json_encode(array_filter($meta, static fn(mixed $value): bool => $value !== null), JSON_THROW_ON_ERROR),
            'source_slug' => $source,
            'crawled_at' => now(),
        ]);
    }

    private function addMovieGallery(Movie $movie, string $source, string $key, string $title, string $url, mixed $position = null): int
    {
        $meta = ['gallery_id' => $key, 'gallery_title' => $title];
        if ($position !== null) {
            $meta['position'] = $position;
        }

        return (int) DB::table('movie_media')->insertGetId([
            'movie_id' => $movie->id,
            'kind' => 'gallery',
            'url' => $url,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'source_slug' => $source,
            'crawled_at' => now(),
        ]);
    }

    private function removeGalleryTitle(string $table, int $mediaId): void
    {
        /** @var array<string, mixed> $meta */
        $meta = json_decode((string) DB::table($table)->where('id', $mediaId)->value('meta'), true, flags: JSON_THROW_ON_ERROR);
        unset($meta['gallery_title']);
        DB::table($table)->where('id', $mediaId)->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);
    }

    /** @return list<class-string<\Illuminate\Foundation\Http\FormRequest>> */
    private function photoRequestTypes(): array
    {
        return [
            \App\Http\Requests\Photos\PhotosGroupsRequest::class,
            \App\Http\Requests\Photos\PhotosGalleriesRequest::class,
            \App\Http\Requests\Photos\PhotosGalleryImagesRequest::class,
            \App\Http\Requests\Photos\PhotosFacetListRequest::class,
        ];
    }

    /** @param class-string<\Illuminate\Foundation\Http\FormRequest> $requestClass
     *  @param array<string, mixed> $query
     */
    private function resolveRequest(string $requestClass, array $query): \Illuminate\Foundation\Http\FormRequest
    {
        if ($requestClass === \App\Http\Requests\Photos\PhotosFacetListRequest::class) {
            $query = array_merge(['facet' => 'source', 'sort' => 'count', 'min_count' => '0'], $query);
        }

        $request = $requestClass::create('/', 'GET', $query);
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();

        return $request;
    }

    /** @param class-string<\Illuminate\Foundation\Http\FormRequest> $requestClass
     *  @param array<string, mixed> $query
     */
    private function assertRequestInvalid(string $requestClass, array $query): void
    {
        try {
            $this->resolveRequest($requestClass, $query);
            self::fail('Invalid numeric input should be rejected.');
        } catch (\App\Exceptions\InvalidFilterException) {
            self::assertTrue(true);
        }
    }
}
