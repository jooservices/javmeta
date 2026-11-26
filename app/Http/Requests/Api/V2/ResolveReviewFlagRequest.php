<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class ResolveReviewFlagRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'state' => ['required', 'in:resolved'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
