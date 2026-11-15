<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SourceCircuitOpened;
use App\Services\Alerts\AlertDispatcher;

final class SendSourceCircuitAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    /** Every opening is its own notification cause, so a reopening with a longer cooldown is never held back by the cooldown of the previous alert. */
    public static function causeKey(string $sourceSlug, int $openCount): string
    {
        return 'source:' . $sourceSlug . ':circuit_open:' . $openCount;
    }

    public function handle(SourceCircuitOpened $event): void
    {
        $this->alerts->sendTemplate(
            debounceKey: self::causeKey($event->sourceSlug, $event->openCount),
            templateKey: 'crawl.source_circuit_open',
            context: [
                'source_slug' => $event->sourceSlug,
                'consecutive_blocks' => $event->consecutiveBlocks,
                'cooldown_seconds' => $event->cooldownSeconds,
                'resume_at' => $event->resumeAt->format(DATE_ATOM),
            ],
        );
    }
}
