<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\TelegramBot;
use Illuminate\Console\Command;

final class TelegramPollCommand extends Command
{
    protected $signature = 'telegram:poll';

    protected $description = 'Read new Telegram messages of the owner (short polling) and answer them (setting: telegram.bot_enabled).';

    public function handle(TelegramBot $bot): int
    {
        $result = $bot->poll();
        $this->line(sprintf('Answered %d, ignored %d%s.', $result['handled'], $result['ignored'], $result['skipped'] !== null ? ' (' . $result['skipped'] . ')' : ''));

        return self::SUCCESS;
    }
}
