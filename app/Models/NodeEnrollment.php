<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A one-time permission for a new node to join. The token is never stored, only its hash.
 *
 * @property int $id
 * @property string $node_name
 * @property string $role
 * @property string $token_hash
 * @property \Carbon\CarbonInterface $expires_at
 * @property \Carbon\CarbonInterface|null $used_at
 * @property string|null $used_from_ip
 * @property int|null $created_by
 */
class NodeEnrollment extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'node_enrollments';

    /** @var list<string> */
    protected $fillable = ['node_name', 'role', 'token_hash', 'expires_at', 'used_at', 'used_from_ip', 'created_by'];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime', 'created_by' => 'integer'];
    }

    public function status(\DateTimeInterface $now): string
    {
        return match (true) {
            $this->used_at !== null => self::STATUS_USED,
            $this->expires_at->lessThanOrEqualTo($now) => self::STATUS_EXPIRED,
            default => self::STATUS_PENDING,
        };
    }
}
