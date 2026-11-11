<?php

declare(strict_types=1);

namespace App\Http\Requests\Photos;

use App\Data\Photos\PhotosImagesQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PhotosFacetListRequest extends FormRequest
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
            'facet' => ['required', Rule::in(['source', 'performer'])],
            'q' => ['nullable', 'string', 'max:200'],
            'page' => ['required', 'integer', 'min:1', 'max:' . self::MAX_PAGE],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . PhotosImagesQuery::MAX_PER_PAGE],
            'sort' => ['required', Rule::in(['count', 'name'])],
            'min_count' => ['required', 'integer', 'min:0', 'max:1000000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'facet' => (string) $this->query('facet', ''),
            'q' => trim((string) $this->query('q', '')),
            'page' => $this->normalizedPage($this->query('page', 1)),
            'per_page' => $this->query('per_page', 20),
            'sort' => (string) $this->query('sort', 'count'),
            'min_count' => $this->query('min_count', 0),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(__('photos.invalid_facet'), [
            'facet' => ['source', 'performer'],
            'per_page' => ['1 to ' . PhotosImagesQuery::MAX_PER_PAGE],
            'sort' => ['count', 'name'],
        ]);
    }

    /** @return array{facet: string, q: string, page: int, per_page: int, sort: string, min_count: int} */
    public function facetParams(): array
    {
        /** @var array{facet: string, q: string, page: int, per_page: int, sort: string, min_count: int} $data */
        $data = $this->validated();

        return [
            'facet' => $data['facet'],
            'q' => (string) $data['q'],
            'page' => (int) $data['page'],
            'per_page' => (int) $data['per_page'],
            'sort' => $data['sort'],
            'min_count' => (int) $data['min_count'],
        ];
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
