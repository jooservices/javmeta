<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PerformerMoviesRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['sort' => ['nullable', 'in:release_date,-release_date,score,-score,maker,code']];
    }
}
