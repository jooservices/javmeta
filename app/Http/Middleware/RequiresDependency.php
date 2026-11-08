<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\DependencyUnavailableException;
use App\Services\Dependencies\Dependency;
use App\Services\Dependencies\DependencyMonitor;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequiresDependency
{
    public function __construct(private DependencyMonitor $monitor) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next, string $dependency): Response
    {
        $dependency = Dependency::tryFrom($dependency);
        if ($dependency === null) {
            throw new InvalidArgumentException('Unknown runtime dependency.');
        }

        if (! $this->monitor->isAvailable($dependency)) {
            throw new DependencyUnavailableException($dependency);
        }

        return $next($request);
    }
}
