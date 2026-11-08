<?php

declare(strict_types=1);

namespace App\Console\Commands\Notifications;

use App\Services\Notifications\NotificationRegistry;
use App\Services\Notifications\NotificationRenderer;
use Illuminate\Console\Command;
use InvalidArgumentException;

final class RenderNotificationCommand extends Command
{
    protected $signature = 'notifications:render {key : Supported notification key} {--sample : Render with registry sample values}';

    protected $description = 'Show a notification template or render it with safe sample values.';

    public function handle(NotificationRegistry $registry, NotificationRenderer $renderer): int
    {
        $key = (string) $this->argument('key');
        if ($registry->get($key) === null) {
            $this->components->error('The notification type is not supported.');

            return self::FAILURE;
        }

        try {
            if ((bool) $this->option('sample')) {
                $templates = $renderer->templates($key);
                $message = $renderer->preview($key, $templates['title_template'], $templates['body_template'], $registry->samples($key));
                $this->line($message['title'] . "\n\n" . $message['body']);

                return self::SUCCESS;
            }

            $message = $renderer->templates($key);
            $this->line($message['title_template'] . "\n\n" . $message['body_template']);

            return self::SUCCESS;
        } catch (InvalidArgumentException $error) {
            $this->components->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
