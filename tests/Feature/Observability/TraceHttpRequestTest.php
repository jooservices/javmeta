<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Http\Middleware\TraceHttpRequest;
use App\Jobs\EmitObservabilitySpan;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class TraceHttpRequestTest extends TestCase
{
    public function test_it_queues_spans_and_returns_trace_context_without_sending_http_inline(): void
    {
        config(['openobserve.enabled' => true]);
        config(['queue.default' => 'redis', 'javmeta_queues.observability_sample_percent' => 100, 'javmeta_queues.observability_depth_limit' => 1000]);
        Http::fake();
        Queue::fake();

        $request = Request::create('/api/v1/search/parse?q=private-question', 'POST');
        $request->headers->set('traceparent', '00-' . str_repeat('a', 32) . '-' . str_repeat('b', 16) . '-01');
        $response = app(TraceHttpRequest::class)->handle($request, static fn() => new JsonResponse(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $traceparent = (string) $response->headers->get('traceparent');
        $this->assertMatchesRegularExpression('/^00-' . str_repeat('a', 32) . '-[a-f0-9]{16}-01$/', $traceparent);

        Queue::assertPushedOn('observability', EmitObservabilitySpan::class);
        Queue::assertPushed(EmitObservabilitySpan::class, static function (EmitObservabilitySpan $job): bool {
            $span = json_encode($job->span, JSON_THROW_ON_ERROR);

            return ($job->span['name'] ?? null) === 'POST /api/v1/search/parse'
                && ($job->span['parent_span_id'] ?? null) === str_repeat('b', 16)
                && ! str_contains($span, 'private-question');
        });
        Http::assertNothingSent();
    }

    public function test_disabled_observability_keeps_the_response_traceparent_without_queuing(): void
    {
        config(['openobserve.enabled' => false]);
        config(['queue.default' => 'redis', 'javmeta_queues.observability_sample_percent' => 100]);
        Queue::fake();

        $request = Request::create('/api/v1/search/parse', 'POST');
        $response = app(TraceHttpRequest::class)->handle($request, static fn() => new JsonResponse(['ok' => true]));

        $this->assertNotEmpty($response->headers->get('traceparent'));
        Queue::assertNotPushed(EmitObservabilitySpan::class);
    }

    public function test_it_does_not_run_span_delivery_inline_on_the_sync_queue(): void
    {
        config(['openobserve.enabled' => true, 'queue.default' => 'sync', 'javmeta_queues.observability_sample_percent' => 100]);
        Queue::fake();

        $response = app(TraceHttpRequest::class)->handle(
            Request::create('/api/v1/search/parse', 'POST'),
            static fn() => new JsonResponse(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
        Queue::assertNotPushed(EmitObservabilitySpan::class);
        Http::assertNothingSent();
    }

    public function test_it_drops_sampled_spans_when_the_observability_queue_is_full(): void
    {
        config(['openobserve.enabled' => true, 'queue.default' => 'redis', 'javmeta_queues.observability_sample_percent' => 100, 'javmeta_queues.observability_depth_limit' => 1]);
        Queue::fake();
        EmitObservabilitySpan::dispatch(['name' => 'already queued']);

        $response = app(TraceHttpRequest::class)->handle(
            Request::create('/api/v1/search/parse', 'POST'),
            static fn() => new JsonResponse(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
        Queue::assertPushed(EmitObservabilitySpan::class, 1);
    }

    public function test_it_deterministically_samples_spans_before_checking_the_queue(): void
    {
        config(['openobserve.enabled' => true, 'queue.default' => 'redis', 'javmeta_queues.observability_sample_percent' => 0]);
        Queue::fake();

        $response = app(TraceHttpRequest::class)->handle(
            Request::create('/api/v1/search/parse', 'POST'),
            static fn() => new JsonResponse(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
        Queue::assertNotPushed(EmitObservabilitySpan::class);
    }

    public function test_queue_failure_does_not_fail_the_api_response(): void
    {
        config(['openobserve.enabled' => true, 'queue.default' => 'redis', 'javmeta_queues.observability_sample_percent' => 100]);
        Queue::fake();
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);

        $response = app(TraceHttpRequest::class)->handle(
            Request::create('/api/v1/search/parse', 'POST'),
            static fn() => new JsonResponse(['ok' => true]),
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->headers->get('traceparent'));
    }
}
