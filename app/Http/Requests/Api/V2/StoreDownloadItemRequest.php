<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class StoreDownloadItemRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'magnet' => ['required', 'string', 'max:8192', 'regex:/^magnet:\?.*xt=urn:btih:[A-Za-z0-9]+/i'],
            'movie_code' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }
}
