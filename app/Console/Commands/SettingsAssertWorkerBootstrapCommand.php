<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;

final class SettingsAssertWorkerBootstrapCommand extends Command
{
    protected $signature = 'settings:assert-worker-bootstrap';

    protected $description = 'Verify the primary imported valid worker environment settings before a worker restart.';

    public function handle(SettingsService $settings): int
    {
        $missing = $settings->missingWorkerBootstrapSettings();
        if ($missing === []) {
            $this->components->info('Cấu hình worker đã được primary nhập và hợp lệ.');

            return self::SUCCESS;
        }

        $this->components->error('Cần chạy settings:import-env trên primary trước khi khởi động lại unit worker; thiếu hoặc không hợp lệ: ' . implode(', ', $missing));

        return self::FAILURE;
    }
}
