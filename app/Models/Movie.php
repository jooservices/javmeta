<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MovieFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Movie extends Model
{
    /** @use HasFactory<MovieFactory> */
    use HasFactory;
    use HasUuids;

    public const CENSORED_UNCENSORED = 0;
    public const CENSORED_CENSORED = 1;
    public const RECRAWL_STATUS_COMPLETE_AS_POSSIBLE = 'complete_as_possible';

    protected $table = 'movies';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'uuid', 'display_code', 'code_normalized', 'title_jp', 'title_en', 'description',
        'release_date', 'runtime_minutes', 'censored', 'maker', 'label', 'series',
        'community_score', 'cover_url', 'cover_thumb_url', 'attrs', 'completeness_tier',
        'recrawl_attempts', 'recrawl_status', 'delisted_at', 'needs_review', 'first_seen_at', 'updated_at', 'crawled_at',
    ];

    /** @return list<string> */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /** @return HasMany<MovieCode, $this> */
    public function codes(): HasMany
    {
        return $this->hasMany(MovieCode::class);
    }

    /** @return HasMany<MovieObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(MovieObservation::class);
    }

    /** @return HasMany<MovieGenre, $this> */
    public function movieGenres(): HasMany
    {
        return $this->hasMany(MovieGenre::class);
    }

    /** @return BelongsToMany<Genre, $this> */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'movie_genres')->withPivot(['source_slug', 'crawled_at']);
    }

    /** @return HasMany<MovieMedia, $this> */
    public function media(): HasMany
    {
        return $this->hasMany(MovieMedia::class);
    }

    /** @return BelongsToMany<Performer, $this> */
    public function performers(): BelongsToMany
    {
        return $this->belongsToMany(Performer::class, 'movie_performers')
            ->whereNull('performers.canonical_performer_id')
            ->withPivot(['source_slug', 'crawled_at']);
    }

    /** @return HasMany<MovieSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(MovieSource::class);
    }

    /** @return HasMany<MovieCredit, $this> */
    public function credits(): HasMany
    {
        return $this->hasMany(MovieCredit::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'release_date' => 'date',
            'runtime_minutes' => 'integer',
            'censored' => 'integer',
            'community_score' => 'decimal:2',
            'completeness_tier' => 'integer',
            'recrawl_attempts' => 'integer',
            'attrs' => 'array',
            'delisted_at' => 'datetime',
            'needs_review' => 'boolean',
            'first_seen_at' => 'datetime',
            'updated_at' => 'datetime',
            'crawled_at' => 'datetime',
        ];
    }
}
