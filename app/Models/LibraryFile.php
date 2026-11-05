<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryFile extends Model
{
    protected $table = 'library_files';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'folder',
        'path',
        'size',
        'mtime',
        'quick_hash',
        'code',
        'movie_id',
        'last_seen_at',
        'missing_at',
        'first_seen_at',
    ];
}
