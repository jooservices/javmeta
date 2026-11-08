<?php

declare(strict_types=1);

namespace App\Data\Library;

/** Current totals for the local movie library. */
final readonly class LibrarySummary
{
    /**
     * @param list<array{folder: string, files: int, bytes: int}> $byFolder
     */
    public function __construct(
        public int $files,
        public int $matched,
        public int $unmatched,
        public int $missing,
        public int $bytes,
        public array $byFolder,
        public ?string $lastScanAt,
        public bool $configured,
    ) {}

    /** @return array{files: int, matched: int, unmatched: int, missing: int, bytes: int, by_folder: list<array{folder: string, files: int, bytes: int}>, last_scan_at: ?string, configured: bool} */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'matched' => $this->matched,
            'unmatched' => $this->unmatched,
            'missing' => $this->missing,
            'bytes' => $this->bytes,
            'by_folder' => $this->byFolder,
            'last_scan_at' => $this->lastScanAt,
            'configured' => $this->configured,
        ];
    }
}
