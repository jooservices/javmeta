<?php

declare(strict_types=1);

namespace App\Events;

final readonly class SettingsApplied
{
    /** @param list<string> $keys */
    public function __construct(public array $keys) {}
}
