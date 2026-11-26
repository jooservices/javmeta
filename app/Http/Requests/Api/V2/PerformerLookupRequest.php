<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PerformerLookupRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['ids' => ['required', 'array', 'min:1', 'max:500'], 'ids.*' => ['required', 'integer', 'min:1']];
    }
}
