<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PreviewTemplateRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title_template' => ['sometimes', 'string', 'max:10000'],
            'body_template' => ['sometimes', 'string', 'max:30000'],
            'variables' => ['sometimes', 'array'],
        ];
    }
}
