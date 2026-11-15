<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Facets\FacetListQuery;
use App\Exceptions\InvalidFilterException;
use App\Models\Movie;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Paginated facet values for the current movie result set. */
final class MovieFacetListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'facet' => ['required', Rule::in(FacetListQuery::MOVIE_FACETS)],
            'q' => ['sometimes', 'string', 'max:200'],
            'sort' => ['required', Rule::in(FacetListQuery::SORTS)],
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . FacetListQuery::MAX_PER_PAGE],
            'min_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'keyword' => ['sometimes', 'string', 'min:2', 'max:200'],
            'code' => ['sometimes', 'string', 'min:2'],
            'genre' => ['sometimes', 'string'],
            'actress' => ['sometimes', 'string'],
            'maker' => ['sometimes', 'string'],
            'series' => ['sometimes', 'string'],
            'label' => ['sometimes', 'string'],
            'released_from' => ['sometimes', 'date_format:Y-m-d'],
            'released_to' => ['sometimes', 'date_format:Y-m-d'],
            'runtime_min' => ['sometimes', 'integer'],
            'runtime_max' => ['sometimes', 'integer'],
            'censored' => ['sometimes', 'in:0,1,censored,uncensored'],
            'on_disk' => ['sometimes', 'in:0,1'],
            'actress_id' => ['sometimes', 'string', 'regex:/^\d+(,\d+)*$/'],
            'genre_all' => ['sometimes', 'in:0,1'],
            'source' => ['sometimes', 'string', 'max:200'],
            'score_min' => ['sometimes', 'numeric', 'min:0', 'max:10'],
            'tier_min' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'has_cover' => ['sometimes', 'in:0,1'],
            'has_magnet' => ['sometimes', 'in:0,1'],
            'has_performers' => ['sometimes', 'in:0,1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'facet' => (string) $this->query('facet', ''),
            'q' => trim((string) $this->query('q', '')),
            'sort' => (string) $this->query('sort', 'count'),
            'page' => max(1, (int) $this->query('page', 1)),
            'per_page' => (int) $this->query('per_page', FacetListQuery::DEFAULT_PER_PAGE),
            'min_count' => (int) $this->query('min_count', FacetListQuery::DEFAULT_MIN_COUNT),
            'keyword' => trim((string) $this->query('keyword', '')),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException('Invalid facet list filter.', $validator->errors()->toArray());
    }

    public function facetListQuery(): FacetListQuery
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();
        $filters = $data;
        unset($filters['facet'], $filters['q'], $filters['sort'], $filters['page'], $filters['per_page'], $filters['min_count'], $filters['keyword']);

        $keyword = trim((string) ($data['keyword'] ?? ''));
        if ($keyword !== '') {
            $filters['q'] = $keyword;
        }

        if (array_key_exists('censored', $filters)) {
            $filters['censored'] = in_array($filters['censored'], [1, '1', 'censored'], true)
                ? Movie::CENSORED_CENSORED
                : Movie::CENSORED_UNCENSORED;
        }

        return new FacetListQuery(
            facet: (string) $data['facet'],
            query: trim((string) ($data['q'] ?? '')),
            sort: (string) $data['sort'],
            perPage: (int) $data['per_page'],
            page: (int) $data['page'],
            minCount: (int) $data['min_count'],
            filters: $filters,
        );
    }
}
