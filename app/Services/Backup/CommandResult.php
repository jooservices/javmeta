<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * The outcome of one external dump process: only the exit code and a diagnostic error output that the caller may show
 * after redacting anything sensitive. Credentials never appear here.
 */
final class CommandResult
{
    public function __construct(
        private readonly int $exitCode,
        private readonly string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function errorOutput(): string
    {
        return $this->errorOutput;
    }
}
