<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AlertEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'rule',
        'severity',
        'value',
        'threshold',
        'started_at',
        'resolved_at',
        'notified_at',
        'channel_state',
        'recovery_completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'float',
            'threshold' => 'float',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'notified_at' => 'datetime',
            'recovery_completed_at' => 'datetime',
            'channel_state' => 'array',
        ];
    }
}
