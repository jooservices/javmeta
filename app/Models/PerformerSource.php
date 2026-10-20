<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerSource extends Model
{
    protected $table = 'performer_sources';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'performer_id', 'site_slug', 'mongo_id', 'external_id', 'source_url', 'crawled_at',
        'source_performer_id', 'name_romaji', 'name_kanji', 'name_kana', 'birth_date',
        'cross_link_source', 'cross_link_id', 'removed_at',
    ];

    /** @return BelongsTo<Performer, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(Performer::class);
    }

    /** @return BelongsTo<Performer, $this> */
    public function sourcePerformer(): BelongsTo
    {
        return $this->belongsTo(Performer::class, 'source_performer_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['crawled_at' => 'datetime', 'birth_date' => 'date', 'removed_at' => 'datetime'];
    }
}
