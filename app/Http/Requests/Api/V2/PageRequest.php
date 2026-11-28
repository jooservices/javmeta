<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

/** page and per_page of the paged lists (50 per page by default, 100 at most); the library files list may filter by match. */
final class PageRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'match' => ['sometimes', 'in:matched,unmatched'],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }

    public function match(): ?string
    {
        $match = $this->validated('match');

        return is_string($match) ? $match : null;
    }
}
