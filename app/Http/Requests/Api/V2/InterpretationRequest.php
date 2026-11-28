<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class InterpretationRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'max:300'],
            'target' => ['nullable', 'in:movies,performers'],
            'ignore' => ['nullable', 'array', 'max:50'],
            'ignore.*' => ['string', 'max:200'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['q' => trim((string) $this->input('q', ''))]);
    }
}
