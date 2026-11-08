<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->query('month') === '') {
            $this->merge(['month' => null]);
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException('Invalid month.', ['month' => ['YYYY-MM']]);
    }

    public function month(): ?string
    {
        $month = $this->validated('month');

        return is_string($month) && $month !== '' ? $month : null;
    }
}
