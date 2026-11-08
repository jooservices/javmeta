<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\EmitObservabilitySpan;
use App\Observability\OpenObserveClient;
use App\Observability\TelemetrySanitizer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(EmitObservabilitySpan::class)]
final class EmitObservabilitySpanTest extends TestCase
{
    public function test_the_job_runs_once_on_the_configured_observability_queue(): void
    {
        config(['javmeta_queues.observability_queue' => 'obs-test']);

        $job = new EmitObservabilitySpan(['trace_id' => 't']);

        self::assertSame('obs-test', $job->queue);
        self::assertSame(1, $job->tries);
        self::assertSame(['trace_id' => 't'], $job->span);
    }

    public function test_handling_the_job_hands_the_span_to_the_client_which_stays_quiet_while_disabled(): void
    {
        config(['openobserve.enabled' => false]);
        Http::fake();

        (new EmitObservabilitySpan(['trace_id' => 't', 'span_id' => 's', 'name' => 'n', 'start_ns' => 1, 'end_ns' => 2]))
            ->handle(new OpenObserveClient(new TelemetrySanitizer()));

        Http::assertNothingSent();
    }
}
