<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SearchMovieDownloadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Reserved for future filters (e.g. preferred source); empty body is valid.
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'source.string' => __('downloads.validation.source_string'),
            'source.max' => __('downloads.validation.source_max'),
        ];
    }
}
