<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Miss;

use App\Events\ConsumerMissedLookup;
use App\Services\Miss\ConsumerMissReporter;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(ConsumerMissReporter::class)]
final class ConsumerMissReporterTest extends TestCase
{
    public function test_a_missed_lookup_is_announced_with_the_trimmed_query_and_context(): void
    {
        Event::fake([ConsumerMissedLookup::class]);

        (new ConsumerMissReporter())->report('movie', '  ABC-123  ', ['source' => 'api']);

        Event::assertDispatched(ConsumerMissedLookup::class, static fn(ConsumerMissedLookup $event): bool => $event->entity === 'movie' && $event->query === 'ABC-123' && $event->context === ['source' => 'api']);
    }

    public function test_a_blank_query_is_not_a_miss(): void
    {
        Event::fake([ConsumerMissedLookup::class]);

        (new ConsumerMissReporter())->report('performer', "  \n ");

        Event::assertNothingDispatched();
    }
}
