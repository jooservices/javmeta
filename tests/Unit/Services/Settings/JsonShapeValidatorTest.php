<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Settings;

use App\Services\Settings\JsonShapeValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

#[CoversClass(JsonShapeValidator::class)]
final class JsonShapeValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, array<array-key, mixed>}> */
    public static function acceptedValues(): iterable
    {
        yield 'node overrides by role' => ['node_worker_map', ['main' => ['default' => 2, 'queues' => ['listing' => 3]], 'worker' => ['browser' => 4, 'retry' => 1]]];
        yield 'node overrides by hostname' => ['node_worker_map', ['worker@node-1.lan' => ['direct' => 2]]];
        yield 'empty node map' => ['node_worker_map', []];
        yield 'queue worker counts' => ['queue_worker_map', ['listing' => 32]];
        yield 'source gap bounds' => ['source_gap_override_map', ['javdb' => ['min' => 4, 'default' => 8, 'max' => 60]]];
        yield 'retry profile' => ['retry_fetch_profile', ['preferred_methods' => ['http', 'flaresolverr']]];
        yield 'performer junk tokens' => ['performer_junk_tokens', []];
    }

    /** @param array<array-key, mixed> $value */
    #[DataProvider('acceptedValues')]
    public function test_a_valid_value_raises_no_error(string $shape, array $value): void
    {
        app(JsonShapeValidator::class)->validate($shape, $value, $this->failure());

        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, array<array-key, mixed>, string}> */
    public static function rejectedValues(): iterable
    {
        yield 'unknown shape' => ['no_such_shape', [], 'has an unsupported JSON shape'];
        yield 'node key is not main, worker or role@host' => ['node_worker_map', ['database' => ['default' => 1]], 'must map main, worker, or role@hostname to worker overrides'];
        yield 'node override is a list' => ['node_worker_map', ['main' => [1, 2]], 'must map main, worker, or role@hostname to worker overrides'];
        yield 'node queues are not an object' => ['node_worker_map', ['main' => ['queues' => []]], 'node queues must be an object of known queues and worker counts'];
        yield 'node queue is unknown' => ['node_worker_map', ['main' => ['queues' => ['unknown' => 2]]], 'node queue overrides must use known queues and worker counts from 1 to 32'];
        yield 'node queue count is zero' => ['node_worker_map', ['main' => ['queues' => ['listing' => 0]]], 'node queue overrides must use known queues and worker counts from 1 to 32'];
        yield 'node role count above 32' => ['node_worker_map', ['main' => ['default' => 33]], 'node overrides may define default, direct, browser, and retry worker counts from 1 to 32'];
        yield 'node override name is unknown' => ['node_worker_map', ['main' => ['listing' => 2]], 'node overrides may define default, direct, browser, and retry worker counts from 1 to 32'];
        yield 'queue is unknown' => ['queue_worker_map', ['unknown' => 1], 'must map known queue names to worker counts from 1 to 32'];
        yield 'queue count is zero' => ['queue_worker_map', ['listing' => 0], 'must map known queue names to worker counts from 1 to 32'];
        yield 'queue count above 32' => ['queue_worker_map', ['listing' => 33], 'must map known queue names to worker counts from 1 to 32'];
        yield 'source slug is malformed' => ['source_gap_override_map', ['Bad Slug' => ['min' => 4, 'default' => 8, 'max' => 60]], 'must map source slugs to min, default, and max gap values'];
        yield 'source is unknown' => ['source_gap_override_map', ['unknown-source' => ['min' => 4, 'default' => 8, 'max' => 60]], 'references unknown source [unknown-source]'];
        yield 'source misses a bound' => ['source_gap_override_map', ['javdb' => ['min' => 4, 'default' => 8]], 'each source must define only min, default, and max'];
        yield 'source gap below 0.25 seconds' => ['source_gap_override_map', ['javdb' => ['min' => 0.1, 'default' => 8, 'max' => 60]], 'gap values must be numbers from 0.25 to 600 seconds'];
        yield 'source bounds out of order' => ['source_gap_override_map', ['javdb' => ['min' => 9, 'default' => 8, 'max' => 60]], 'each source must satisfy min <= default <= max'];
        yield 'retry profile has an unsupported field' => ['retry_fetch_profile', ['secret' => 'x'], 'contains unsupported fetch profile fields'];
    }

    /** @param array<array-key, mixed> $value */
    #[DataProvider('rejectedValues')]
    public function test_an_invalid_value_reports_the_matching_message(string $shape, array $value, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        app(JsonShapeValidator::class)->validate($shape, $value, $this->failure());
    }

    /** @return callable(string): never */
    private function failure(): callable
    {
        return static fn(string $why): never => throw new InvalidArgumentException($why);
    }
}
