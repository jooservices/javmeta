<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/** Query of the paged lists under /me: page, per_page and the filters each list knows (marks: kind list and target; movies and history: one kind). */
final class MeListRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $kinds = $this->is('api/v2/me/history') ? 'in:movie,performer,photo,download' : ($this->is('api/v2/me/marks/movies') ? 'in:favorite,watched,want,hidden' : 'regex:/^[a-z,]+$/');

        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'kind' => ['sometimes', 'string', $kinds],
            'target' => ['sometimes', 'string', 'in:movie,performer'],
        ];
    }
}
