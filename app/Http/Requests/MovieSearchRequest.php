<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\InvalidFilterException;
use App\Models\Movie;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class MovieSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'string', 'min:2'],
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
            'sort' => ['sometimes', 'in:relevance,release_date,update_date,rating'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'semantic' => ['sometimes', 'in:0,1,true,false'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $q = $this->input('q');

        if (is_string($q)) {
            $this->merge(['q' => trim($q)]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $q = $this->input('q');

            // AC-4.5: a term made only of symbols is not searchable.
            if (is_string($q) && $q !== '' && preg_match('/[\p{L}\p{N}]/u', $q) !== 1) {
                $validator->errors()->add('q', 'The q parameter must contain at least one letter or digit.');
            }

            if ($this->boolean('semantic')) {
                if (! is_string($q) || $q === '') {
                    $validator->errors()->add('q', 'The q parameter is required for semantic search.');
                }

                if ($this->hasAny([
                    'code', 'genre', 'actress', 'maker', 'series', 'label',
                    'released_from', 'released_to', 'runtime_min', 'runtime_max',
                    'censored', 'on_disk', 'sort', 'cursor',
                ])) {
                    $validator->errors()->add('semantic', 'Semantic search supports only q and per_page.');
                }
            }

            $from = $this->input('released_from');
            $to = $this->input('released_to');

            // Y-m-d strings compare lexicographically like dates.
            if ($this->isDate($from) && $this->isDate($to) && $to < $from) {
                $validator->errors()->add('released_to', 'released_to must be greater than or equal to released_from.');
            }

            $min = $this->input('runtime_min');
            $max = $this->input('runtime_max');

            if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                $validator->errors()->add('runtime_max', 'runtime_max must be greater than or equal to runtime_min.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(
            'Invalid movie search filter.',
            $validator->errors()->toArray(),
        );
    }

    /**
     * The validated filter values, with censored normalized to its integer
     * constant so the repository never parses string forms.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();
        unset($filters['sort'], $filters['cursor'], $filters['per_page'], $filters['semantic']);

        if (array_key_exists('censored', $filters)) {
            $filters['censored'] = in_array($filters['censored'], [1, '1', 'censored'], true)
                ? Movie::CENSORED_CENSORED
                : Movie::CENSORED_UNCENSORED;
        }

        return $filters;
    }

    private function isDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }
}
