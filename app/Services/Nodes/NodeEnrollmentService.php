<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Data\Nodes\EnrollmentGrant;
use App\Data\Nodes\JoinBundle;
use App\Events\NodeEnrolled;
use App\Events\NodeEnrollmentCreated;
use App\Models\NodeEnrollment;
use App\Repositories\NodeEnrollmentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/** Lets a super administrator hand a new VM a one-time permission to join, and hands the install answers to the node that redeems it. */
final class NodeEnrollmentService
{
    public function __construct(
        private readonly NodeEnrollmentRepository $enrollments,
        private readonly JoinBundleFactory $bundles,
        private readonly Dispatcher $events,
    ) {}

    public function create(string $nodeName, string $role, ?int $createdBy): EnrollmentGrant
    {
        if (! in_array($role, (array) config('javmeta_nodes.roles', ['worker']), true)) {
            throw new InvalidArgumentException('Unsupported node role.');
        }
        $now = CarbonImmutable::now();
        $expiresAt = $now->addMinutes(max(5, (int) config('javmeta_nodes.enrollment_ttl_minutes', 30)));
        $token = 'jne_' . bin2hex(random_bytes(32));

        $enrollment = $this->enrollments->createPending($nodeName, $role, $this->hash($token), $expiresAt, $createdBy);
        $this->events->dispatch(new NodeEnrollmentCreated($enrollment->id, $nodeName, $role, $createdBy));

        return new EnrollmentGrant(
            $enrollment->id,
            $nodeName,
            $role,
            $token,
            'curl -fsSL ' . rtrim((string) config('app.url'), '/') . '/node/join.sh | sudo bash -s -- --token ' . $token . ' --name ' . $nodeName,
            $expiresAt,
        );
    }

    /** The join bundle, or null when the token is unknown, for another node, already used or expired (the caller cannot tell which). */
    public function redeem(string $token, string $nodeName, string $ip): ?JoinBundle
    {
        $enrollment = $this->enrollments->redeem($this->hash($token), $nodeName, $ip, CarbonImmutable::now());
        if ($enrollment === null) {
            return null;
        }
        $this->events->dispatch(new NodeEnrolled($enrollment->id, $enrollment->node_name, $enrollment->role, $ip));

        return $this->bundles->for($enrollment->node_name, $enrollment->role);
    }

    /** @return Collection<int, array{id: int, node_name: string, role: string, status: string, expires_at: string, used_at: string|null}> */
    public function recent(int $limit = 20): Collection
    {
        $now = CarbonImmutable::now();

        return $this->enrollments->recent($limit)->map(static fn(NodeEnrollment $enrollment): array => [
            'id' => $enrollment->id,
            'node_name' => $enrollment->node_name,
            'role' => $enrollment->role,
            'status' => $enrollment->status($now),
            'expires_at' => $enrollment->expires_at->toIso8601String(),
            'used_at' => $enrollment->used_at?->toIso8601String(),
        ])->values();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
