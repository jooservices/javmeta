<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class MovieLookupRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['codes' => ['required', 'array', 'min:1', 'max:500'], 'codes.*' => ['required', 'string', 'max:64']];
    }
}
