<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CrawlQueueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CrawlQueue extends Model
{
    /** @use HasFactory<CrawlQueueFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const KIND_LISTING = 'listing';
    public const KIND_DETAIL = 'detail';
    public const KIND_PERFORMER_LISTING = 'performer_listing';
    public const KIND_PERFORMER_DETAIL = 'performer_detail';
    public const KIND_GALLERY = 'gallery';

    protected $table = 'crawl_queue';
    public $timestamps = false;
    /** @var list<string> */
    protected $fillable = ['source_slug', 'url', 'kind', 'status', 'attempts', 'max_attempts', 'next_attempt_at', 'claimed_at', 'started_at', 'locked_by', 'last_error', 'error_code', 'last_failed_at'];

    /**
     * Map a crawlerx item's nextCrawlType to a queue kind. Falls back to the
     * entity's detail kind when the item is terminal (no next crawl type).
     */
    public static function kindForNextCrawlType(?string $nextCrawlType, string $entityType): string
    {
        return match ($nextCrawlType) {
            'listing' => self::KIND_LISTING,
            'detail' => self::KIND_DETAIL,
            'performer_listing' => self::KIND_PERFORMER_LISTING,
            'performer_detail' => self::KIND_PERFORMER_DETAIL,
            'gallery' => self::KIND_GALLERY,
            default => match ($entityType) {
                'performer' => self::KIND_PERFORMER_DETAIL,
                'gallery' => self::KIND_GALLERY,
                default => self::KIND_DETAIL,
            },
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempts' => 'integer', 'max_attempts' => 'integer', 'next_attempt_at' => 'datetime', 'claimed_at' => 'datetime', 'started_at' => 'datetime', 'last_failed_at' => 'datetime'];
    }
}
