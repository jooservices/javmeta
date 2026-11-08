<?php

declare(strict_types=1);

namespace App\Http\Requests\Nodes;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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

    /** A missing or malformed token means the caller presented no credential: 401, like any other endpoint that needs one. */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('token')) {
            throw new HttpResponseException(response()->json(['message' => 'A valid node token is required.'], 401)->header('Cache-Control', 'no-store'));
        }

        parent::failedValidation($validator);
    }
}
