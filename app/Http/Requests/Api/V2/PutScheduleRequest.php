<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PutScheduleRequest extends FormRequest
{
    private const SECTIONS = 'in:clusters,hosts,services,workers';

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'periodic' => ['required', 'array'],
            'periodic.enabled' => ['sometimes', 'boolean'],
            'periodic.every_minutes' => ['sometimes', 'integer'],
            'periodic.include' => ['sometimes', 'array'],
            'periodic.include.*' => ['string', self::SECTIONS],
            'daily' => ['required', 'array'],
            'daily.enabled' => ['sometimes', 'boolean'],
            'daily.time' => ['sometimes', 'string'],
            'daily.timezone' => ['sometimes', 'string'],
            'daily.include' => ['sometimes', 'array'],
            'daily.include.*' => ['string', self::SECTIONS],
            'quiet_mode' => ['required', 'array'],
            'quiet_mode.enabled' => ['sometimes', 'boolean'],
            'quiet_mode.from' => ['sometimes', 'string'],
            'quiet_mode.to' => ['sometimes', 'string'],
        ];
    }
}
