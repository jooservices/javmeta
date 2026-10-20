<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PerformerMediaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformerMedia extends Model
{
    /** @use HasFactory<PerformerMediaFactory> */
    use HasFactory;

    public const KIND_IMAGE = 'image';
    public const KIND_GALLERY = 'gallery';

    protected $table = 'performer_media';
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['performer_id', 'kind', 'url', 'meta', 'source_slug', 'source_performer_id', 'crawled_at'];

    /** @return BelongsTo<Performer, $this> */
    public function performer(): BelongsTo
    {
        return $this->belongsTo(Performer::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['performer_id' => 'integer', 'meta' => 'array', 'crawled_at' => 'datetime'];
    }
}
