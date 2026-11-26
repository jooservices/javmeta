<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PhotoCollectionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['kind' => ['nullable', 'in:cover,avatar'], 'q' => ['nullable', 'string', 'max:200']];
    }
}
