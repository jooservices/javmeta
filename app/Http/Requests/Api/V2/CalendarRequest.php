<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class CalendarRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'day' => ['nullable', 'date_format:Y-m-d']];
    }
}
