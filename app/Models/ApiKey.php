<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property list<string>|null $scopes */
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    /** movies: lookup, search, related, calendar; bulk: movies:bulk; performers; catalog: meta and suggest; status; mcp. */
    public const SCOPES = ['movies', 'bulk', 'performers', 'catalog', 'status', 'mcp', 'media'];

    protected $table = 'api_keys';
    public $timestamps = false;
    /** @var list<string> */
    protected $fillable = ['prefix', 'key_hash', 'label', 'status', 'revoked_at', 'abuse_rpm', 'scopes', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['revoked_at' => 'datetime', 'abuse_rpm' => 'integer', 'scopes' => 'array', 'created_at' => 'datetime'];
    }

    /** A key without scopes (null) may use everything. */
    public function allows(string $scope): bool
    {
        $scopes = $this->scopes;

        return $scopes === null || in_array($scope, $scopes, true);
    }
}
