<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use Illuminate\Foundation\Http\FormRequest;

final class RedeemNodeEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/^jne_[a-f0-9]{64}$/'],
            'node_name' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/'],
        ];
    }
}
