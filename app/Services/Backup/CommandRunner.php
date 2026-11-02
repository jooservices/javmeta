<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * The process boundary used by the logical backup service. The real implementation runs pg_dump and mongodump through the
 * Laravel Process facade; tests substitute a fake that records commands and fabricates their output files.
 */
interface CommandRunner
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env  Extra environment variables that must never be logged or printed.
     */
    public function run(array $command, array $env = []): CommandResult;
}
