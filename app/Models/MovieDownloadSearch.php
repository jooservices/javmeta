<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $movie_id
 * @property string $state
 * @property int $found_count
 * @property list<string>|null $sources_queued
 * @property list<string>|null $sources_done
 * @property CarbonInterface|null $last_checked_at
 * @property CarbonInterface|null $cooldown_until
 * @property CarbonInterface|null $started_at
 * @property string|null $last_error
 */
class MovieDownloadSearch extends Model
{
    public const STATE_IDLE = 'idle';

    public const STATE_SEARCHING = 'searching';

    public const STATE_FOUND = 'found';

    public const STATE_NOT_FOUND = 'not_found';

    public const STATE_FAILED = 'failed';

    /** @var list<string> */
    public const STATES = [
        self::STATE_IDLE,
        self::STATE_SEARCHING,
        self::STATE_FOUND,
        self::STATE_NOT_FOUND,
        self::STATE_FAILED,
    ];

    protected $table = 'movie_download_searches';

    /** @var list<string> */
    protected $fillable = [
        'movie_id',
        'state',
        'found_count',
        'sources_queued',
        'sources_done',
        'last_checked_at',
        'cooldown_until',
        'started_at',
        'last_error',
    ];

    /** @return BelongsTo<Movie, $this> */
    public function movie(): BelongsTo
    {
        return $this->belongsTo(Movie::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'movie_id' => 'integer',
            'found_count' => 'integer',
            'sources_queued' => 'array',
            'sources_done' => 'array',
            'last_checked_at' => 'datetime',
            'cooldown_until' => 'datetime',
            'started_at' => 'datetime',
        ];
    }
}
