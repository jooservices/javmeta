<?php

declare(strict_types=1);

namespace App\Http\Requests\Photos;

use App\Data\Photos\PhotosGalleryQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class PhotosGalleriesRequest extends FormRequest
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
            'performer' => ['nullable', 'integer', 'min:1'],
            'movie' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['required', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . PhotosGalleryQuery::MAX_PER_PAGE],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'performer' => $this->query('performer') !== null && $this->query('performer') !== '' ? $this->query('performer') : null,
            'movie' => trim((string) $this->query('movie', '')),
            'source' => trim((string) $this->query('source', '')),
            'q' => trim((string) $this->query('q', '')),
            'page' => $this->normalizedPage($this->query('page', 1)),
            'per_page' => $this->query('per_page', 24),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(__('photos.invalid_galleries_query'), [
            'per_page' => ['1 to ' . PhotosGalleryQuery::MAX_PER_PAGE],
        ]);
    }

    public function photosGalleryQuery(): PhotosGalleryQuery
    {
        /** @var array{performer: int|null, movie: string, source: string, q: string, page: int, per_page: int} $data */
        $data = $this->validated();
        $performerId = isset($data['performer']) ? (int) $data['performer'] : null;
        $movieCode = $data['movie'] !== '' ? (string) $data['movie'] : null;
        if ($performerId === null && $movieCode === null) {
            throw new InvalidFilterException(__('photos.galleries_need_owner'));
        }

        return new PhotosGalleryQuery(
            performerId: $performerId,
            movieCode: $movieCode,
            source: (string) $data['source'],
            q: (string) $data['q'],
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
