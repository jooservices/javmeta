<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class PerformerSearchRequest extends FormRequest
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
            'age_min' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'age_max' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'height_min' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'height_max' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'bust_min' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'bust_max' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'waist_min' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'waist_max' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'hip_min' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'hip_max' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'cup' => ['sometimes', 'string', 'max:60'],
            'blood_type' => ['sometimes', 'string', 'max:60'],
            'location' => ['sometimes', 'string', 'max:255'],
            'has_image' => ['sometimes', 'in:0,1'],
            'has_bio' => ['sometimes', 'in:0,1'],
            'status' => ['sometimes', 'in:active,retired'],
            'movies_min' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'debut_from' => ['sometimes', 'integer', 'min:1950', 'max:2100'],
            'debut_to' => ['sometimes', 'integer', 'min:1950', 'max:2100'],
            'source' => ['sometimes', 'string', 'max:200'],
            'sort' => ['sometimes', 'in:name,updated_at,linked_title_count'],
            'cursor' => ['sometimes', 'string'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $q = $this->input('q');
        if (is_string($q)) {
            $this->merge(['q' => trim($q)]);
        }

        $location = $this->input('location');
        if (is_string($location)) {
            $this->merge(['location' => trim($location)]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ([
                ['age_min', 'age_max'],
                ['height_min', 'height_max'],
                ['bust_min', 'bust_max'],
                ['waist_min', 'waist_max'],
                ['hip_min', 'hip_max'],
            ] as [$minimum, $maximum]) {
                $min = $this->input($minimum);
                $max = $this->input($maximum);

                if (is_numeric($min) && is_numeric($max) && (int) $max < (int) $min) {
                    $validator->errors()->add($maximum, "{$maximum} must be greater than or equal to {$minimum}.");
                }
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException(
            'Invalid performer search filter.',
            $validator->errors()->toArray(),
        );
    }
}
