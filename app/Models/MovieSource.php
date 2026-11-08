<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieSource extends Model
{
    protected $table = 'movie_sources';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'movie_id', 'site_slug', 'mongo_id', 'source_url', 'source_code', 'crawled_at',
    ];

    /**
     * One row per movie and source (the unique key): a new crawl of the same source updates it, never adds a second row.
     */
    public static function recordArchive(int $movieId, string $siteSlug, string $mongoId, ?string $sourceUrl, ?string $sourceCode, mixed $crawledAt): self
    {
        return self::query()->updateOrCreate(
            ['movie_id' => $movieId, 'site_slug' => $siteSlug],
            ['mongo_id' => $mongoId, 'source_url' => $sourceUrl, 'source_code' => $sourceCode, 'crawled_at' => $crawledAt],
        );
    }

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['crawled_at' => 'datetime'];
    }
}
