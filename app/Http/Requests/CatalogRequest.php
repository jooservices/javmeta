<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Catalog\CatalogQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CatalogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(CatalogQuery::KINDS)],
            'sort' => ['required', Rule::in(CatalogQuery::SORTS)],
            'per_page' => ['required', 'integer', 'min:1', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $kind = (string) ($this->route('kind') ?? basename($this->path()));
        $this->merge([
            'kind' => $kind,
            'q' => trim((string) $this->query('q', '')),
            'sort' => (string) $this->query('sort', $kind === 'years' ? 'name' : 'movie_count'),
            'per_page' => (int) $this->query('per_page', 50),
            'page' => max(1, (int) $this->query('page', 1)),
            'include_unknown' => $this->boolean('include_unknown'),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('kind')) {
            throw new InvalidFilterException('Unknown catalog.');
        }

        throw new InvalidFilterException('Invalid sort or per_page.', ['sort' => ['movie_count or name'], 'per_page' => ['1 to 200']]);
    }

    public function catalogQuery(): CatalogQuery
    {
        /** @var array{kind: string, sort: string, per_page: int|string} $data */
        $data = $this->validated();

        return new CatalogQuery(
            kind: $data['kind'],
            query: (string) $this->input('q', ''),
            sort: $data['sort'],
            perPage: (int) $data['per_page'],
            page: (int) $this->input('page', 1),
            includeUnknown: (bool) $this->input('include_unknown', false),
        );
    }
}
