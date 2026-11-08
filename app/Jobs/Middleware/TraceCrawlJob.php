<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use App\Repositories\CrawlQueueRepository;
use App\Models\CrawlQueue;
use App\Observability\OpenObserveClient;
use App\Observability\TraceContext;
use App\Services\Crawl\WorkerHeartbeat;
use App\Services\Observability\NodeEgressProbe;
use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

final class TraceCrawlJob
{
    public function __construct(
        private readonly OpenObserveClient $client,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly NodeEgressProbe $egressProbe,
    ) {}

    /**
     * @param  object  $job
     * @param  Closure(object): mixed  $next
     */
    public function handle(object $job, Closure $next): mixed
    {
        $this->heartbeat->beat();

        $parsed = TraceContext::parseTraceparent(
            property_exists($job, 'traceparent') && is_string($job->traceparent) ? $job->traceparent : null,
        );
        $traceId = $parsed['trace_id'] ?? TraceContext::newTraceId();
        $parentSpanId = $parsed['span_id'] ?? null;
        $spanId = TraceContext::newSpanId();
        $startNs = (int) hrtime(true);

        $sourceSlug = null;
        $queueKind = null;
        $lane = null;
        if (property_exists($job, 'crawlQueueId')) {
            /** @var mixed $queueId */
            $queueId = $job->crawlQueueId;
            if (is_int($queueId)) {
                $row = app(CrawlQueueRepository::class)->modelQuery(CrawlQueue::class)->find($queueId);
                if ($row instanceof CrawlQueue) {
                    $sourceSlug = $row->source_slug;
                    $queueKind = $row->kind;
                    $lane = $row->getAttribute('claimed_lane') === 'browser' ? 'browser' : 'direct';
                }
            }
        }

        $egress = $this->egressProbe->contextForLane($lane);
        $previousLogContext = Log::sharedContext();
        Log::shareContext($egress);

        $outcome = 'ok';
        $result = null;
        try {
            $result = $next($job);
        } catch (Throwable $exception) {
            $outcome = 'error';
            throw $exception;
        } finally {
            try {
                $this->client->ingestSpan([
                    'name' => sprintf('job.%s', $job::class),
                    'trace_id' => $traceId,
                    'span_id' => $spanId,
                    'parent_span_id' => $parentSpanId,
                    'start_ns' => $startNs,
                    'end_ns' => (int) hrtime(true),
                    'status_code' => $outcome === 'ok' ? 200 : 500,
                    'attributes' => [
                        'source_slug' => $sourceSlug,
                        'queue.kind' => $queueKind,
                        'outcome' => $outcome,
                        'job.class' => $job::class,
                        'node' => $egress['node'],
                        'egress_ip' => $egress['egress_ip'],
                    ],
                ]);
            } finally {
                Log::withoutContext(['node', 'egress_ip', 'egress_kind']);
                Log::flushSharedContext();
                Log::shareContext($previousLogContext);
            }
        }

        return $result;
    }
}
