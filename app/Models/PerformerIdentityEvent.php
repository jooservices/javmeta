<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** @property array<string, mixed> $details */
class PerformerIdentityEvent extends Model
{
    protected $table = 'performer_identity_events';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'event_type', 'from_performer_id', 'to_performer_id', 'related_event_id',
        'details', 'created_at', 'reversed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'from_performer_id' => 'integer',
            'to_performer_id' => 'integer',
            'related_event_id' => 'integer',
            'details' => 'array',
            'created_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }
}
