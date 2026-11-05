<?php

declare(strict_types=1);

namespace App\Data\Library;

/** File totals grouped by movie code. */
final readonly class LibraryOnDisk
{
    /** @param list<array{code: string, files: int, bytes: int}> $codes */
    public function __construct(public array $codes) {}

    /** @return list<array{code: string, files: int, bytes: int}> */
    public function toArray(): array
    {
        return $this->codes;
    }
}
