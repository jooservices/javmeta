<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use JOOservices\LaravelConfig\Contracts\ConfigStore;

final class SettingsPurgeTelegramCredentialsCommand extends Command
{
    protected $signature = 'settings:purge-telegram-credentials';

    protected $description = 'Remove legacy Telegram credentials from database settings.';

    public function handle(ConfigStore $store): int
    {
        $removed = $store->forgetMany([
            'settings.telegram.bot_token',
            'settings.telegram.chat_id',
        ]);

        $this->components->info("Removed {$removed} legacy Telegram credential setting(s).");

        return self::SUCCESS;
    }
}
