<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateNodeEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'node_name' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[a-z][a-z0-9-]*$/'],
            'role' => ['required', 'string', Rule::in((array) config('javmeta_nodes.roles', ['worker']))],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['node_name.regex' => __('operations.validation.node_name_regex')];
    }
}
