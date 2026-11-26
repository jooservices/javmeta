<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class StoreNodeEnrollmentRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'node_name' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/'],
            'role' => ['sometimes', 'in:worker,main'],
        ];
    }
}
