<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ToggleUiMarkRequest extends FormRequest
{
    private ?string $customValidationMessage = null;

    /** @var array<string, list<string>> */
    private const KINDS = [
        'favourite' => ['movie'],
        'watch_later' => ['movie'],
        'seen' => ['movie'],
        'follow' => ['performer', 'maker', 'series'],
        'saved_search' => ['movies', 'performers'],
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'subject' => ['required', 'string'],
            'key' => ['required', 'string', $this->input('kind') === 'saved_search' ? 'max:2000' : 'max:300'],
            'on' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $kind = $this->input('kind');
            $subject = $this->input('subject');
            if (! is_string($kind) || ! is_string($subject)) {
                return;
            }

            if (! in_array($subject, self::KINDS[$kind] ?? [], true)) {
                $this->customValidationMessage = 'This mark does not apply to that subject.';
                $validator->errors()->add('mark', $this->customValidationMessage);

                return;
            }

            if ($kind === 'saved_search' && ! $this->isValidSavedSearch($this->input('key'))) {
                $this->customValidationMessage = 'A saved search requires a name (1–80 characters) and a query (up to 1500 characters).';
                $validator->errors()->add('mark', $this->customValidationMessage);
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->customValidationMessage !== null) {
            throw new HttpResponseException(response()->json(['message' => $this->customValidationMessage], 422));
        }

        parent::failedValidation($validator);
    }

    private function isValidSavedSearch(mixed $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $search = json_decode($key, true);

        return is_array($search)
            && is_string($search['name'] ?? null)
            && trim($search['name']) !== ''
            && mb_strlen($search['name']) <= 80
            && is_string($search['query'] ?? null)
            && mb_strlen($search['query']) <= 1500;
    }
}
