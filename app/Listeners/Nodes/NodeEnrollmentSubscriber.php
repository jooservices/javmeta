<?php

declare(strict_types=1);

namespace App\Listeners\Nodes;

use App\Events\NodeEnrolled;
use App\Events\NodeEnrollmentCreated;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Log;

/** Records who created and who redeemed a node enrollment. Neither the token nor the bundle is ever logged. */
final class NodeEnrollmentSubscriber
{
    public function created(NodeEnrollmentCreated $event): void
    {
        Log::info('javmeta.node_enrollment_created', [
            'enrollment_id' => $event->enrollmentId,
            'node' => $event->nodeName,
            'role' => $event->role,
            'created_by' => $event->createdBy,
        ]);
    }

    public function enrolled(NodeEnrolled $event): void
    {
        Log::info('javmeta.node_enrolled', [
            'enrollment_id' => $event->enrollmentId,
            'node' => $event->nodeName,
            'role' => $event->role,
            'ip' => $event->ip,
        ]);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            NodeEnrollmentCreated::class => 'created',
            NodeEnrolled::class => 'enrolled',
        ];
    }
}
