<?php

declare(strict_types=1);

namespace Tests\Feature\Health;

use App\Services\Health\StartupHealthService;
use Illuminate\Console\Command;
use Tests\Support\StartupHealthTestFixture;
use Tests\TestCase;

final class StartupHealthCommandTest extends TestCase
{
    public function test_it_exits_successfully_when_primary_dependencies_pass_even_if_a_secondary_fails(): void
    {
        $fixture = StartupHealthTestFixture::bind(['sentry' => false]);
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 0])
            ->expectsOutputToContain('sentry')
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_it_exits_with_failure_when_a_primary_dependency_is_down(): void
    {
        $fixture = StartupHealthTestFixture::bind(['postgres' => false]);
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 0])
            ->expectsOutputToContain('Startup blocked')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_it_rejects_an_invalid_role_or_wait_value(): void
    {
        $fixture = StartupHealthTestFixture::bind();
        $this->app->instance(StartupHealthService::class, $fixture['health']);

        $this->artisan('health:startup', ['--role' => 'maintenance', '--wait' => 0])->assertExitCode(Command::FAILURE);
        $this->artisan('health:startup', ['--role' => 'worker', '--wait' => 'invalid'])->assertExitCode(Command::FAILURE);
    }
}
