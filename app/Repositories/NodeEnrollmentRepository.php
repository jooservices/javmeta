<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Models\NodeEnrollment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use JOOservices\LaravelRepository\Contracts\CrudRepositoryInterface;
use JOOservices\LaravelRepository\Contracts\ReadRepositoryInterface;
use JOOservices\LaravelRepository\Repositories\EloquentRepository;
use JOOservices\LaravelRepository\Traits\HasCrud;
use JOOservices\LaravelRepository\Traits\HasFilter;
use JOOservices\LaravelRepository\Traits\HasOrder;
use JOOservices\LaravelRepository\Traits\HasRead;
use JOOservices\LaravelRepository\Traits\HasRequestQuery;

/** Stores node enrollments and redeems them atomically. */
final class NodeEnrollmentRepository extends EloquentRepository implements CrudRepositoryInterface, ReadRepositoryInterface
{
    use HasCrud;
    use HasFilter;
    use HasOrder;
    use HasRead;
    use HasRequestQuery;

    public function __construct(NodeEnrollment $model)
    {
        parent::__construct($model);
    }

    public function createPending(string $nodeName, string $role, string $tokenHash, CarbonImmutable $expiresAt, ?int $createdBy): NodeEnrollment
    {
        /** @var NodeEnrollment $enrollment */
        $enrollment = $this->newQuery()->create([
            'node_name' => $nodeName,
            'role' => $role,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy,
        ]);

        return $enrollment;
    }

    /**
     * Marks an unused, unexpired enrollment for this node as used and returns it; null when there is none.
     * The check and the update are one statement, so two concurrent redemptions cannot both win.
     */
    public function redeem(string $tokenHash, string $nodeName, string $ip, CarbonImmutable $now): ?NodeEnrollment
    {
        $updated = $this->newQuery()
            ->where('token_hash', $tokenHash)
            ->where('node_name', $nodeName)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['used_at' => $now, 'used_from_ip' => $ip, 'updated_at' => $now]);
        if ($updated !== 1) {
            return null;
        }

        /** @var NodeEnrollment|null $enrollment */
        $enrollment = $this->newQuery()->where('token_hash', $tokenHash)->first();

        return $enrollment;
    }

    /** @return Collection<int, NodeEnrollment> */
    public function recent(int $limit): Collection
    {
        /** @var Collection<int, NodeEnrollment> $enrollments */
        $enrollments = $this->newQuery()->orderByDesc('id')->limit(max(1, $limit))->get();

        return $enrollments;
    }
}
