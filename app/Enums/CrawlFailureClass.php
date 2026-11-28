<?php

declare(strict_types=1);

namespace App\Enums;

/** Whether a person has to act on a failed crawl, and on what: the verdict that decides what reaches Sentry and the reports. */
enum CrawlFailureClass: string
{
    /** An answer the site is expected to give (page not found, login required, rate limit). Recorded, never an error. */
    case ExpectedOutcome = 'expected_outcome';

    /** The site answers but the fetch chain cannot pass it (challenge, forbidden, server error). */
    case RealBlocker = 'real_blocker';

    /** A fault on our side: network, time-out, SSRF policy, a worker that failed. */
    case OwnFault = 'own_fault';

    /** A defect in the code or the data: a parse failure or a code nobody classified. */
    case Defect = 'defect';
}
