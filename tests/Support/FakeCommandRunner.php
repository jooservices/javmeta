<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Backup\CommandResult;
use App\Services\Backup\CommandRunner;

/**
 * Deterministic stand-in for the external dump processes: records every command and environment, optionally writes a
 * small file at the `-f`/`--archive` destination, and can fail one binary by name.
 */
final class FakeCommandRunner implements CommandRunner
{
    public int $exitCode = 0;

    public bool $createFiles = true;

    public ?string $fail = null;

    /** @var list<array{command: list<string>, env: array<string, string>}> */
    public array $runs = [];

    /** @var list<array{path: string, mode: int, contents: string}> */
    public array $observedConfigFiles = [];

    public function run(array $command, array $env = []): CommandResult
    {
        $this->runs[] = ['command' => $command, 'env' => $env];
        foreach ($command as $index => $argument) {
            if ($argument !== '--config' || ! isset($command[$index + 1])) {
                continue;
            }

            $path = $command[$index + 1];
            $permissions = fileperms($path);
            $this->observedConfigFiles[] = [
                'path' => $path,
                'mode' => $permissions === false ? 0 : $permissions & 0777,
                'contents' => (string) file_get_contents($path),
            ];
        }

        $exitCode = $this->fail !== null && ($command[0] ?? '') === $this->fail ? 1 : $this->exitCode;

        if ($this->createFiles && $exitCode === 0) {
            $file = $this->outputFile($command);
            if ($file !== null) {
                $directory = dirname($file);
                if (! is_dir($directory)) {
                    mkdir($directory, 0777, true);
                }
                file_put_contents($file, str_repeat('x', 64));
            }
        }

        return new CommandResult($exitCode);
    }

    /** @return list<array{command: list<string>, env: array<string, string>}> */
    public function commandsFor(string $binary): array
    {
        return array_values(array_filter($this->runs, static fn(array $run): bool => ($run['command'][0] ?? '') === $binary));
    }

    /** @param  list<string>  $command */
    private function outputFile(array $command): ?string
    {
        for ($i = 0; $i < count($command) - 1; $i++) {
            if ($command[$i] === '-f' || $command[$i] === '--archive') {
                return (string) $command[$i + 1];
            }
        }

        return null;
    }
}
