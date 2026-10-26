<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SearchParseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:2', 'max:300']];
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
            if (is_string($q) && preg_match('/[\p{L}\p{N}]/u', $q) !== 1) {
                $validator->errors()->add('q', 'The q parameter must contain at least one letter or digit.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException('Invalid natural-language question.', $validator->errors()->toArray());
    }
}
