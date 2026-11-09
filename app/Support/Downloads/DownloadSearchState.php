<?php

declare(strict_types=1);

namespace App\Support\Downloads;

enum DownloadSearchState: string
{
    case IDLE = 'idle';
    case SEARCHING = 'searching';
    case FOUND = 'found';
    case NOT_FOUND = 'not_found';
    case FAILED = 'failed';
}
