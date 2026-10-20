<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerAlias extends Model
{
    protected $table = 'performer_aliases';
    public $timestamps = false;
    /** @var list<string> */
    protected $fillable = ['performer_id', 'alias', 'kind', 'source_slug', 'source_performer_id'];

    /** @return BelongsTo<Performer, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(Performer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['performer_id' => 'integer'];
    }
}
