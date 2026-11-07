<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SourceCircuitOpened;
use App\Services\Alerts\AlertDispatcher;

final class SendSourceCircuitAlert
{
    public function __construct(private readonly AlertDispatcher $alerts) {}

    public function handle(SourceCircuitOpened $event): void
    {
        $this->alerts->sendTemplate(
            debounceKey: 'source:' . $event->sourceSlug . ':circuit_open',
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
