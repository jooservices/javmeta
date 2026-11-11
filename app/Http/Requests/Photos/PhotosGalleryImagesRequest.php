<?php

declare(strict_types=1);

namespace App\Http\Requests\Photos;

use App\Data\Photos\PhotosImagesQuery;
use App\Data\Photos\GalleryId;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class PhotosGalleryImagesRequest extends FormRequest
{
    private const MAX_PAGE = 46116860184273880;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['required', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . PhotosImagesQuery::MAX_PER_PAGE],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'page' => $this->normalizedPage($this->query('page', 1)),
            'per_page' => $this->query('per_page', PhotosImagesQuery::DEFAULT_PER_PAGE),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(__('photos.invalid_images_query'), [
            'per_page' => ['1 to ' . PhotosImagesQuery::MAX_PER_PAGE],
        ]);
    }

    /**
     * The gallery id comes from the route parameter of the controller, not from this request: behind the UI bridge
     * the form request is built from the outer request, which has no `id` route parameter.
     */
    public function photosImagesQuery(string $galleryId): PhotosImagesQuery
    {
        if ($galleryId === '' || strlen($galleryId) > GalleryId::MAX_ENCODED_LENGTH) {
            throw new InvalidFilterException(__('photos.invalid_images_query'), ['gallery_id' => ['1 to ' . GalleryId::MAX_ENCODED_LENGTH . ' characters']]);
        }

        /** @var array{page: int, per_page: int} $data */
        $data = $this->validated();

        return new PhotosImagesQuery(
            galleryId: $galleryId,
            page: (int) $data['page'],
            perPage: (int) $data['per_page'],
        );
    }

    private function normalizedPage(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return 1;
        }
        if (is_int($value)) {
            return max(1, $value);
        }
        if (! is_string($value) || preg_match('/\A([+-]?)([0-9]+)\z/', $value, $matches) !== 1) {
            return $value;
        }

        $digits = ltrim($matches[2], '0');
        $canonical = ($matches[1] === '-' && $digits !== '' ? '-' : '') . ($digits === '' ? '0' : $digits);
        $integer = filter_var($canonical, FILTER_VALIDATE_INT);

        return $integer === false ? $value : max(1, $integer);
    }
}
