<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Exceptions\DependencyUnavailableException;
use App\Http\Middleware\RequiresDependency;
use App\Services\Dependencies\DependencyMonitor;
use App\Services\Dependencies\DependencyProbe;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(RequiresDependency::class)]
final class RequiresDependencyTest extends TestCase
{
    public function test_the_request_continues_when_the_dependency_is_available(): void
    {
        $response = $this->middleware(true)->handle(Request::create('/x'), static fn(): Response => new Response('ok'), 'postgres');

        self::assertSame('ok', $response->getContent());
    }

    public function test_the_request_stops_with_a_service_unavailable_problem_when_it_is_down(): void
    {
        $this->expectException(DependencyUnavailableException::class);

        $this->middleware(false)->handle(Request::create('/x'), static fn(): Response => new Response('never'), 'postgres');
    }

    public function test_an_unknown_dependency_name_is_a_programming_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown runtime dependency.');

        $this->middleware(true)->handle(Request::create('/x'), static fn(): Response => new Response('never'), 'not-a-dependency');
    }

    private function middleware(bool $available): RequiresDependency
    {
        $probe = new class ($available) implements DependencyProbe {
            public function __construct(private readonly bool $available) {}

            public function probe(): bool
            {
                return $this->available;
            }
        };

        return new RequiresDependency(new DependencyMonitor(['postgres' => $probe]));
    }
}
