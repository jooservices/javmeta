<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\Health\StartupCommandGate;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Events\CommandStarting;

final readonly class StartupCommandListener
{
    public function __construct(
        private SettingsService $settings,
        private StartupCommandGate $gate,
    ) {}

    public function handle(CommandStarting $event): void
    {
        $this->settings->apply();
        $this->gate->handle($event->command);
    }
}
