<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class SearchRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'max:200'], 'types' => ['nullable', 'string', 'max:64']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['q' => trim((string) $this->query('q', ''))]);
    }
}
