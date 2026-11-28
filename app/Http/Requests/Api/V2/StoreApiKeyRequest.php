<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use App\Models\ApiKey;
use Illuminate\Foundation\Http\FormRequest;

final class StoreApiKeyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'scopes' => ['sometimes', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:' . implode(',', ApiKey::SCOPES)],
            'abuse_rpm' => ['sometimes', 'integer', 'min:1', 'max:10000'],
        ];
    }
}
