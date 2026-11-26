<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateMeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'current_password' => ['sometimes', 'nullable', 'string'],
            'new_password' => ['sometimes', 'string', 'min:8'],
        ];
    }
}
