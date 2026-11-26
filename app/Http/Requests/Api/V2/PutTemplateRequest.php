<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

final class PutTemplateRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title_template' => ['sometimes', 'string', 'max:10000'],
            'body_template' => ['sometimes', 'string', 'max:30000'],
            'enabled' => ['sometimes', 'boolean'],
            'default_severity' => ['sometimes', 'in:info,notice,warning,critical'],
            'tier' => ['sometimes', 'in:info,notice,warning,critical'],
            'cooldown_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'escalation_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'include_in_periodic_report' => ['sometimes', 'boolean'],
            'include_in_daily_report' => ['sometimes', 'boolean'],
            'send_resolved' => ['sometimes', 'boolean'],
        ];
    }
}
