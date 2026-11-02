<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\Process;

/**
 * The real command runner: delegates to the Laravel Process facade, passes extra environment variables (such as
 * PGPASSWORD) without placing them on the command line, and never prints them. A generous timeout covers large dumps.
 */
final class ProcessCommandRunner implements CommandRunner
{
    public function run(array $command, array $env = []): CommandResult
    {
        $result = Process::env($env)->timeout(3600)->run($command);

        return new CommandResult($result->exitCode(), rtrim((string) $result->errorOutput()));
    }
}
