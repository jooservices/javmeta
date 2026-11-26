<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateSavedSearchRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:80'],
            'query' => ['sometimes', 'string', 'min:1', 'max:1500'],
        ];
    }
}
