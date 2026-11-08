<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\Auth\ApiKeyCreateData;
use Illuminate\Foundation\Http\FormRequest;

final class CreateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'abuse_rpm' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', 'in:' . implode(',', \App\Models\ApiKey::SCOPES)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Chọn ít nhất một quyền.',
            'scopes.min' => 'Chọn ít nhất một quyền.',
        ];
    }

    public function createData(): ApiKeyCreateData
    {
        $validated = $this->validated();
        /** @var list<string>|null $scopes */
        $scopes = $validated['scopes'] ?? null;

        return new ApiKeyCreateData(
            (string) $validated['label'],
            (int) ($validated['abuse_rpm'] ?? 60),
            $scopes,
        );
    }
}
