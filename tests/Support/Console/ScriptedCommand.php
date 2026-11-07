<?php

declare(strict_types=1);

namespace Tests\Support\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Stand-in for an Artisan command: records the options it receives and returns a scripted exit code. */
final class ScriptedCommand extends Command
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @param list<string> $options option names the stand-in accepts */
    public function __construct(string $name, private readonly int $exitCode = self::SUCCESS, private readonly array $options = ['source', 'limit'])
    {
        parent::__construct($name);

        foreach ($this->options as $option) {
            $this->addOption($option, null, InputOption::VALUE_OPTIONAL);
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $received = [];
        foreach ($this->options as $option) {
            $value = $input->getOption($option);
            if ($value !== null) {
                $received[$option] = $value;
            }
        }
        $this->calls[] = $received;

        return $this->exitCode;
    }
}
