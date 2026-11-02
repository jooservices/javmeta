<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Alerts\AlertChannelDispatcher;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Console\Command;

final class AlertsTestCommand extends Command
{
    protected $signature = 'alerts:test';

    protected $description = 'Send a test alert through every enabled notification channel.';

    public function handle(AlertDispatcher $dispatcher, AlertChannelDispatcher $channels): int
    {
        $names = $channels->enabledNames();
        if ($names === []) {
            $this->components->error('Chưa bật kênh nhận cảnh báo nào.');

            return self::FAILURE;
        }

        $sent = $dispatcher->dispatchChannels(
            'Tin nhắn thử cảnh báo',
            'Đây là tin thử từ XCrawler. Nếu bạn nhận được tin này, kênh cảnh báo đang hoạt động.',
            ['alert_state' => 'open', 'severity' => 'info', 'test' => true],
            force: true,
        );

        foreach ($names as $name) {
            if (in_array($name, $sent, true)) {
                $this->components->info("{$name}: đã gửi.");

                continue;
            }

            $this->components->error("{$name}: gửi thất bại hoặc chưa được cấu hình.");
        }

        return count($sent) === count($names) ? self::SUCCESS : self::FAILURE;
    }
}
