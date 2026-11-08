<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UnmatchedLibraryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['page' => ['required', 'integer', 'min:1']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['page' => max(1, (int) $this->query('page', 1))]);
    }

    public function page(): int
    {
        /** @var array{page: int|string} $data */
        $data = $this->validated();

        return (int) $data['page'];
    }
}
