<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class StoreMediaJobRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:capture,thumbnail,subtitle'],
            'source_url' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'params' => ['sometimes', 'nullable', 'array'],
            'params.library_file_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
