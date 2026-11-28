<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use App\Services\Api\V2\Me\Preferences;
use Illuminate\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePreferencesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'language' => ['sometimes', 'string', 'in:vi,en'],
            'density' => ['sometimes', 'string', 'in:comfortable,compact'],
            'privacy' => ['sometimes', 'boolean'],
            'shortcuts' => ['sometimes', 'boolean'],
            'stats_hidden' => ['sometimes', 'boolean'],
            'view_mode' => ['sometimes', 'string', 'in:grid,table'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach (array_diff(array_keys($this->json()->all()), Preferences::KEYS) as $key) {
                $validator->addFailure((string) $key, 'unknown');
            }
        });
    }
}
