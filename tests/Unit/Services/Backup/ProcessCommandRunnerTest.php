<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\CommandResult;
use App\Services\Backup\ProcessCommandRunner;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class ProcessCommandRunnerTest extends TestCase
{
    public function test_it_delegates_to_the_process_facade_and_reports_the_exit_code(): void
    {
        Process::fake(['*pg_dump*' => Process::result(exitCode: 1, errorOutput: 'boom')]);

        $result = (new ProcessCommandRunner())->run(['pg_dump', '-V'], ['PGPASSWORD' => 'sekret']);

        $this->assertInstanceOf(CommandResult::class, $result);
        $this->assertSame(1, $result->exitCode());
        $this->assertFalse($result->successful());
        $this->assertSame('boom', $result->errorOutput());

        Process::assertRan(fn($process): bool => $process->command === ['pg_dump', '-V'] && $process->environment['PGPASSWORD'] === 'sekret');
    }
}
