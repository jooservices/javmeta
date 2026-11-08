<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;


    protected $table = 'sources';
    protected $primaryKey = 'slug';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    /** @var list<string> */
    protected $fillable = ['slug', 'name', 'base_url', 'enabled', 'priority', 'needs_proxy', 'gap_seconds_default', 'gap_seconds_min', 'gap_seconds_max', 'gap_seconds_current', 'last_success_at', 'last_error_at', 'last_error'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'priority' => 'integer', 'needs_proxy' => 'boolean', 'gap_seconds_default' => 'decimal:2', 'gap_seconds_min' => 'decimal:2', 'gap_seconds_max' => 'decimal:2', 'gap_seconds_current' => 'decimal:2', 'last_success_at' => 'datetime', 'last_error_at' => 'datetime'];
    }
}
