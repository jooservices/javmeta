<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\FollowNotifier;
use Illuminate\Console\Command;

final class FollowNotifyCommand extends Command
{
    protected $signature = 'follows:notify';

    protected $description = 'Send Telegram messages for new movies of followed performers, makers and series (settings: follow notifications).';

    public function handle(FollowNotifier $notifier): int
    {
        $result = $notifier->run();
        $this->line(sprintf('Sent %d, held back %d%s.', $result['sent'], $result['held'], $result['skipped'] !== null ? ' (' . $result['skipped'] . ')' : ''));

        return self::SUCCESS;
    }
}
