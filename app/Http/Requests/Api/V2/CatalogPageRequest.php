<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class CatalogPageRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'script' => ['nullable', 'in:all,latin,ja,zh,other'],
            'letter' => ['nullable', 'string', 'max:8'],
            'min_count' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', 'in:-count,count,name,-name'],
        ];
    }
}
