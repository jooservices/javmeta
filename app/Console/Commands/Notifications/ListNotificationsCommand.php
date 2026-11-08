<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\NotificationTemplateService;
use Illuminate\Console\Command;

final class ListNotificationsCommand extends Command
{
    protected $signature = 'notifications:list {--json : Print machine-readable JSON}';

    protected $description = 'List supported notification templates without changing them.';

    public function handle(NotificationTemplateService $templates): int
    {
        $rows = array_map(static fn(array $row): array => [
            'key' => $row['key'],
            'name' => $row['name'],
            'channel' => $row['channel'],
            'enabled' => $row['enabled'],
            'version' => $row['version'],
            'sent_count' => $row['sent_count'],
            'last_sent_at' => $row['last_sent_at'],
            'updated_at' => $row['updated_at'],
        ], $templates->all());

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(['Key', 'Name', 'Channel', 'Enabled', 'Version', 'Last sent', 'Last edited'], array_map(
            static fn(array $row): array => [...array_values($row)],
            $rows,
        ));

        return self::SUCCESS;
    }
}
