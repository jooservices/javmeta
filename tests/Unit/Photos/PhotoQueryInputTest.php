<?php

declare(strict_types=1);

namespace Tests\Unit\Photos;

use App\Data\Photos\GalleryId;
use App\Data\Photos\PhotosGalleryQuery;
use App\Data\Photos\PhotosGroupQuery;
use App\Exceptions\InvalidFilterException;
use App\Http\Requests\Photos\PhotosFacetListRequest;
use App\Http\Requests\Photos\PhotosGalleriesRequest;
use App\Http\Requests\Photos\PhotosGalleryImagesRequest;
use App\Http\Requests\Photos\PhotosGroupsRequest;
use App\Repositories\PhotosRepository;
use Tests\TestCase;

final class PhotoQueryInputTest extends TestCase
{
    public function test_malformed_movie_codes_are_validation_errors_for_both_request_paths(): void
    {
        foreach ([PhotosGroupsRequest::class, PhotosGalleriesRequest::class] as $requestClass) {
            foreach (['bogus', '123', 'SSIS-abc', "bad\xFFcode"] as $movieCode) {
                $this->assertRequestInvalid($requestClass, ['movie' => $movieCode]);
            }
        }

        $groupRequest = $this->resolveRequest(PhotosGroupsRequest::class, ['movie' => 'SSIS-0001']);
        $galleryRequest = $this->resolveRequest(PhotosGalleriesRequest::class, ['movie' => 'SSIS-0001']);
        self::assertSame('SSIS-0001', $groupRequest->validated('movie'));
        self::assertSame('SSIS-0001', $galleryRequest->validated('movie'));
    }

    public function test_invalid_movie_codes_return_empty_results_at_each_repository_entry_point(): void
    {
        $repository = app(PhotosRepository::class);

        self::assertSame(
            ['items' => [], 'total' => 0],
            $repository->groups(new PhotosGroupQuery('movie', '', '', null, 'bogus', 1, 20)),
        );
        self::assertSame(
            ['items' => [], 'total' => 0],
            $repository->groups(new PhotosGroupQuery('gallery', '', '', null, 'bogus', 1, 20)),
        );
        self::assertSame(
            ['items' => [], 'total' => 0],
            $repository->galleries(new PhotosGalleryQuery(null, 'bogus', '', '', 1, 20)),
        );
        self::assertSame(
            ['items' => [], 'total' => 0],
            $repository->galleries(new PhotosGalleryQuery(null, '', '', '', 1, 20)),
        );
    }

    public function test_photo_requests_reject_array_and_unsafe_text_inputs_without_casting(): void
    {
        $cases = [
            [PhotosGroupsRequest::class, ['by' => ['performer']]],
            [PhotosGroupsRequest::class, ['q' => ['image']]],
            [PhotosGroupsRequest::class, ['source' => ['source-a']]],
            [PhotosGroupsRequest::class, ['movie' => ['SSIS-1']]],
            [PhotosGroupsRequest::class, ['q' => "contains\0nul"]],
            [PhotosGroupsRequest::class, ['source' => "contains\0nul"]],
            [PhotosGroupsRequest::class, ['movie' => "contains\0nul"]],
            [PhotosGalleriesRequest::class, ['q' => ['album']]],
            [PhotosGalleriesRequest::class, ['source' => ['source-a']]],
            [PhotosGalleriesRequest::class, ['movie' => ['SSIS-1']]],
            [PhotosGalleriesRequest::class, ['q' => "contains\0nul"]],
            [PhotosGalleriesRequest::class, ['source' => "contains\0nul"]],
            [PhotosGalleriesRequest::class, ['movie' => "contains\0nul"]],
            [PhotosGalleriesRequest::class, ['performer' => ['1']]],
            [PhotosFacetListRequest::class, ['facet' => ['source']]],
            [PhotosFacetListRequest::class, ['sort' => ['count']]],
            [PhotosFacetListRequest::class, ['q' => ['source-a']]],
            [PhotosFacetListRequest::class, ['q' => "contains\0nul"]],
            [PhotosGalleryImagesRequest::class, ['page' => ['1']]],
            [PhotosGalleryImagesRequest::class, ['per_page' => ['20']]],
        ];

        foreach ($cases as [$requestClass, $query]) {
            $this->assertRequestInvalid($requestClass, $query);
        }

        foreach ([PhotosGroupsRequest::class, PhotosGalleriesRequest::class, PhotosFacetListRequest::class] as $requestClass) {
            $this->assertRequestInvalid($requestClass, ['q' => "invalid\xFFutf8"]);
        }
    }

    public function test_gallery_id_decoder_rejects_nul_and_invalid_utf8_in_all_encoded_text(): void
    {
        $legacyNul = $this->base64UrlEncode("performer|1|source-a|gallery\0key");
        $v2NulSource = $this->base64UrlEncode('v2|performer|1|' . $this->base64UrlEncode("source\0a") . '|1');
        $legacyInvalidUtf8 = $this->base64UrlEncode("performer|1|source-a|gallery\xFF");
        $v2InvalidUtf8Source = $this->base64UrlEncode('v2|performer|1|' . $this->base64UrlEncode("source\xFF") . '|1');

        foreach ([$legacyNul, $v2NulSource, $legacyInvalidUtf8, $v2InvalidUtf8Source] as $id) {
            try {
                GalleryId::decode($id);
                self::fail('Unsafe gallery identity text must be rejected.');
            } catch (InvalidFilterException) {
                self::assertTrue(true);
            }
        }
    }

    /** @param class-string<\Illuminate\Foundation\Http\FormRequest> $requestClass
     *  @param array<string, mixed> $query
     */
    private function resolveRequest(string $requestClass, array $query): \Illuminate\Foundation\Http\FormRequest
    {
        if ($requestClass === PhotosFacetListRequest::class) {
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
            self::fail('Malformed photo query input must be rejected.');
        } catch (InvalidFilterException) {
            self::assertTrue(true);
        }
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
