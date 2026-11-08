<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReportSnapshot extends Model
{
    /** @var list<string> */
    protected $fillable = ['scheduled_for', 'report_type', 'result', 'channel', 'rendered_text', 'raw_numbers'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_for' => 'immutable_datetime', 'raw_numbers' => 'array'];
    }
}
