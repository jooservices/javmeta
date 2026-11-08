<?php

declare(strict_types=1);

namespace App\Data\Library;

/** Unique movie codes that have at least one current file on disk. */
final readonly class LibraryCodes
{
    /** @param list<string> $codes */
    public function __construct(public array $codes) {}

    /** @return list<string> */
    public function toArray(): array
    {
        return $this->codes;
    }
}
