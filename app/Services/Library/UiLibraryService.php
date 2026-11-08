<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Data\Library\LibraryCodes;
use App\Data\Library\LibraryOnDisk;
use App\Data\Library\LibrarySummary;
use App\Data\Library\UnmatchedLibraryPage;
use App\Repositories\LibraryFileRepository;

/** Read-only use cases for the signed-in owner's library page. */
final class UiLibraryService
{
    public function __construct(private readonly LibraryFileRepository $files) {}

    public function summary(): LibrarySummary
    {
        $summary = $this->files->summary();

        return new LibrarySummary(
            $summary['files'],
            $summary['matched'],
            $summary['unmatched'],
            $summary['missing'],
            $summary['bytes'],
            $summary['by_folder'],
            $summary['last_scan_at'],
            ! empty(array_filter((array) config('javmeta_library.folders', []))),
        );
    }

    public function codes(): LibraryCodes
    {
        return new LibraryCodes($this->files->codes());
    }

    public function unmatched(int $page): UnmatchedLibraryPage
    {
        $result = $this->files->unmatchedPage($page);

        return new UnmatchedLibraryPage($result['files'], $result['total'], $page);
    }

    public function onDisk(): LibraryOnDisk
    {
        return new LibraryOnDisk($this->files->onDisk());
    }
}
