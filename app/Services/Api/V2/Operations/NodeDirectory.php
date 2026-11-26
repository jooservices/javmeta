<?php

declare(strict_types=1);

namespace App\Services\Api\V2\Operations;

use App\Http\Api\V2\ProblemException;
use App\Repositories\Api\V2\NodeOpsRepository;
use App\Services\Api\V2\Contracts\AuditLog;
use App\Services\Nodes\NodeEnrollmentService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use stdClass;

/** The crawler nodes, what each of them did, and the join codes that add a new one. */
final class NodeDirectory
{
    private const JOIN_NOTE = 'The token is shown once and only its hash is stored.';

    public function __construct(
        private readonly NodeOpsRepository $nodes,
        private readonly NodeEnrollmentService $enrollments,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $now = CarbonImmutable::now();
        $totals = [];
        foreach ($this->nodes->totalsSince($now->subDay()) as $row) {
            $totals[(string) $row->node] = $row;
        }

        $byNode = [];
        foreach ($this->nodes->lanesSince($now->subHour()) as $row) {
            $byNode[(string) $row->node][] = [
                'node' => $row->node,
                'lane' => $row->lane,
                'attempts' => (int) $row->attempts,
                'ok' => (int) $row->ok,
                'avg_ms' => $row->avg_ms === null ? null : (int) round((float) $row->avg_ms),
                'last_at' => Iso::stamp($row->last_at),
                'egress_ips' => (int) $row->egress_ips,
            ];
        }

        $nodes = [];
        foreach ($byNode as $name => $lanes) {
            $nodes[] = ['node' => $name, 'lanes' => $lanes, 'attempts_24h' => (int) ($totals[$name]->attempts ?? 0), 'ok_24h' => (int) ($totals[$name]->ok ?? 0)];
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    public function find(string $node): array
    {
        $now = CarbonImmutable::now();

        return [
            'node' => $node,
            'hourly' => array_map(static fn(stdClass $row): array => [
                'hour_at' => Iso::stamp($row->hour_at),
                'attempts' => (int) $row->attempts,
                'ok' => (int) $row->ok,
                'avg_ms' => $row->avg_ms === null ? null : (int) round((float) $row->avg_ms),
            ], $this->nodes->hourlyOfNode($node, $now->subHours(48))),
            'contribution_24h' => array_map(static fn(stdClass $row): array => ['source_slug' => $row->source_slug, 'n' => (int) $row->n], $this->nodes->contributionOfNode($node, $now->subDay())),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function enrollments(): array
    {
        $now = CarbonImmutable::now();

        return array_map(static fn(stdClass $row): array => [
            'id' => (int) $row->id,
            'node_name' => $row->node_name,
            'role' => $row->role,
            'expires_at' => Iso::stamp($row->expires_at),
            'used_at' => Iso::stamp($row->used_at),
            'used_from_ip' => $row->used_from_ip,
            'created_at' => Iso::stamp($row->created_at),
            'state' => match (true) {
                $row->used_at !== null => 'redeemed',
                CarbonImmutable::parse((string) $row->expires_at, 'UTC')->lessThanOrEqualTo($now) => 'expired',
                default => 'created',
            },
            'origin' => 'production',
        ], $this->nodes->enrollments(50));
    }

    /** @return array<string, mixed> */
    public function enroll(string $nodeName, string $role, ?int $actorId): array
    {
        try {
            $grant = $this->enrollments->create($nodeName, $role, $actorId);
        } catch (InvalidArgumentException) {
            throw ProblemException::invalid('This node role is not enabled.', [['field' => 'role', 'code' => 'unsupported']]);
        }

        $this->audit->record($this->actor($actorId), 'node_enrollment.create', "node-enrollments/{$grant->enrollmentId}", ['node_name' => $nodeName, 'role' => $role]);

        return [
            'id' => $grant->enrollmentId,
            'node_name' => $grant->nodeName,
            'role' => $grant->role,
            'expires_at' => Iso::stamp($grant->expiresAt),
            'state' => 'created',
            'join_command' => $grant->command,
            'note' => self::JOIN_NOTE,
        ];
    }

    public function revoke(int $id, ?int $actorId): void
    {
        $this->nodes->revokeUnused($id);
        $this->audit->record($this->actor($actorId), 'node_enrollment.delete', "node-enrollments/{$id}");
    }

    private function actor(?int $actorId): string
    {
        return $actorId === null ? 'system' : 'user:' . $actorId;
    }
}
