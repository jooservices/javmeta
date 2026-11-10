<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Facets\FacetListQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Paginated facet values for the current performer result set. */
final class PerformerFacetListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'facet' => ['required', Rule::in(FacetListQuery::PERFORMER_FACETS)],
            'q' => ['sometimes', 'string', 'max:200'],
            'sort' => ['required', Rule::in(FacetListQuery::SORTS)],
            'page' => ['required', 'integer', 'min:1'],
            'per_page' => ['required', 'integer', 'min:1', 'max:' . FacetListQuery::MAX_PER_PAGE],
            'min_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'keyword' => ['sometimes', 'string', 'min:2', 'max:200'],
            'cup' => ['sometimes', 'string'],
            'blood_type' => ['sometimes', 'string'],
            'location' => ['sometimes', 'string'],
            'source' => ['sometimes', 'string', 'max:200'],
            'status' => ['sometimes', 'in:active,retired'],
            'age_min' => ['sometimes', 'integer', 'min:0'],
            'age_max' => ['sometimes', 'integer', 'min:0'],
            'height_min' => ['sometimes', 'integer', 'min:0'],
            'height_max' => ['sometimes', 'integer', 'min:0'],
            'bust_min' => ['sometimes', 'integer', 'min:0'],
            'bust_max' => ['sometimes', 'integer', 'min:0'],
            'waist_min' => ['sometimes', 'integer', 'min:0'],
            'waist_max' => ['sometimes', 'integer', 'min:0'],
            'hip_min' => ['sometimes', 'integer', 'min:0'],
            'hip_max' => ['sometimes', 'integer', 'min:0'],
            'debut_from' => ['sometimes', 'integer'],
            'debut_to' => ['sometimes', 'integer'],
            'has_image' => ['sometimes', 'in:0,1'],
            'has_bio' => ['sometimes', 'in:0,1'],
            'movies_min' => ['sometimes', 'integer', 'min:0'],
            'include_unknown' => ['sometimes', 'boolean'],
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
            'include_unknown' => $this->boolean('include_unknown'),
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
