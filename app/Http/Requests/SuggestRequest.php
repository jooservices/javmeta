<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Data\Suggest\SuggestQuery;
use App\Exceptions\InvalidFilterException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class SuggestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['required', 'integer', 'min:1', 'max:20'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => trim((string) $this->query('q', '')),
            'limit' => (int) $this->query('limit', 5),
        ]);
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new InvalidFilterException('Invalid q or limit.', ['q' => ['2 to 100 characters'], 'limit' => ['1 to 20']]);
    }

    public function suggestQuery(): SuggestQuery
    {
        /** @var array{q: string, limit: int|string} $data */
        $data = $this->validated();

        return SuggestQuery::from($data['q'], (int) $data['limit']);
    }
}
