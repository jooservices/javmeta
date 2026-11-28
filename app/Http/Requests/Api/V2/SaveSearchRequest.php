<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class SaveSearchRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'query' => ['required', 'string', 'max:1500'],
            'target' => ['sometimes', 'string', 'in:movie,performer'],
        ];
    }
}
