<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UiMarkedMoviesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => [Rule::in(['favourite', 'watch_later', 'seen'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['kind' => $this->route('kind')]);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('per_page') || $validator->errors()->has('page')) {
            parent::failedValidation($validator);
        }

        abort(404);
    }
}
