<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Health\StartupHealthService;
use Illuminate\Console\Command;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupHealthCommandTest extends TestCase
{
    #[DataProvider('secondaryDependencies')]
    public function test_it_exits_successfully_when_a_secondary_dependency_fails(string $dependency): void
    {
        $fixture = StartupHealthTestFixture::bind([$dependency => false]);
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 0])
            ->expectsOutputToContain($dependency)
            ->assertExitCode(Command::SUCCESS);
    }

    #[DataProvider('primaryDependencies')]
    public function test_it_exits_with_failure_when_a_primary_dependency_is_down(string $dependency): void
    {
        $fixture = StartupHealthTestFixture::bind([$dependency => false]);
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 0])
            ->expectsOutputToContain('Startup blocked')
            ->assertExitCode(Command::FAILURE);
    }

    /** @return array<string, array{string}> */
    public static function primaryDependencies(): array
    {
        return [
            'postgres' => ['postgres'],
            'redis' => ['redis'],
            'mongo' => ['mongo'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function secondaryDependencies(): array
    {
        return [
            'elasticsearch on a worker' => ['elasticsearch'],
            'embedder' => ['embedder'],
            'observability' => ['observability'],
            'browser' => ['browser'],
            'sentry' => ['sentry'],
        ];
    }

    public function test_it_rejects_an_invalid_role_or_wait_value(): void
    {
        $fixture = StartupHealthTestFixture::bind();
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'maintenance', '--wait' => 0])->assertExitCode(Command::FAILURE);
        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 'invalid'])->assertExitCode(Command::FAILURE);
    }
}
