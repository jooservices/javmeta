<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use App\Services\Api\V2\Me\ViewHistory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RecordViewRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $ref = $this->input('ref');
        if (is_int($ref)) {
            $this->merge(['ref' => (string) $ref]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(ViewHistory::KINDS)],
            'ref' => ['required', 'string', 'max:2000'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
