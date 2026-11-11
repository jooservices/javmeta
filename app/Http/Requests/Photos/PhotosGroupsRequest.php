<?php

declare(strict_types=1);

namespace App\Http\Requests\Photos;

use App\Data\Photos\PhotosGroupQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PhotosGroupsRequest extends FormRequest
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
            'by' => ['required', Rule::in(PhotosGroupQuery::BY)],
            'q' => ['nullable', 'string', 'max:200'],
            'source' => ['nullable', 'string', 'max:100'],
            'performer' => ['nullable', 'integer', 'min:1'],
            'movie' => ['nullable', 'string', 'max:64'],
            'page' => ['required', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . PhotosGroupQuery::MAX_PER_PAGE],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'by' => (string) $this->query('by', 'performer'),
            'q' => trim((string) $this->query('q', '')),
            'source' => trim((string) $this->query('source', '')),
            'performer' => $this->query('performer') !== null && $this->query('performer') !== '' ? $this->query('performer') : null,
            'movie' => trim((string) $this->query('movie', '')),
            'page' => $this->normalizedPage($this->query('page', 1)),
            'per_page' => $this->query('per_page', 24),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(__('photos.invalid_groups_query'), [
            'by' => PhotosGroupQuery::BY,
            'per_page' => ['1 to ' . PhotosGroupQuery::MAX_PER_PAGE],
        ]);
    }

    public function photosGroupQuery(): PhotosGroupQuery
    {
        /** @var array{by: string, q: string, source: string, performer: int|null, movie: string, page: int, per_page: int} $data */
        $data = $this->validated();

        return new PhotosGroupQuery(
            by: $data['by'],
            q: (string) $data['q'],
            source: (string) $data['source'],
            performerId: isset($data['performer']) ? (int) $data['performer'] : null,
            movieCode: $data['movie'] !== '' ? (string) $data['movie'] : null,
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
