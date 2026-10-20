<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PerformerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property-read Collection<int, PerformerSource> $sources */
class Performer extends Model
{
    /** @use HasFactory<PerformerFactory> */
    use HasFactory;
    use HasUuids;

    protected $table = 'performers';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'source_slug', 'external_id', 'name_romaji', 'name_kanji', 'name_kana',
        'birth_date', 'height_cm', 'bust', 'waist', 'hip', 'cup', 'blood_type', 'bio_text',
        'debut_date', 'retired_date', 'location', 'profile_url', 'image_url', 'attrs', 'needs_review',
        'first_seen_at', 'updated_at', 'crawled_at',
        'canonical_performer_id',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::creating(function (Performer $performer): void {
            if ($performer->getAttribute('attrs') === null) {
                $performer->setAttribute('attrs', []);
            }
            if ($performer->first_seen_at === null) {
                $performer->first_seen_at = $performer->crawled_at ?? now();
            }
            if ($performer->updated_at === null) {
                $performer->updated_at = $performer->crawled_at ?? now();
            }
        });
    }

    /** @return HasMany<PerformerAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(PerformerAlias::class);
    }

    /** @return HasMany<PerformerMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(PerformerMedia::class);
    }

    /** @return HasMany<PerformerSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(PerformerSource::class);
    }

    /** @return HasMany<PerformerIdentityEvent, $this> */
    public function identityEvents(): HasMany
    {
        return $this->hasMany(PerformerIdentityEvent::class, 'to_performer_id');
    }

    /** @return BelongsToMany<Movie, $this> */
    public function movies(): BelongsToMany
    {
        return $this->belongsToMany(Movie::class, 'movie_performers')->withPivot(['source_slug', 'crawled_at']);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'debut_date' => 'date',
            'retired_date' => 'date',
            'height_cm' => 'integer',
            'bust' => 'integer',
            'waist' => 'integer',
            'hip' => 'integer',
            'attrs' => 'array',
            'needs_review' => 'boolean',
            'first_seen_at' => 'datetime',
            'updated_at' => 'datetime',
            'crawled_at' => 'datetime',
            'canonical_performer_id' => 'integer',
        ];
    }
}
