<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use Tests\TestCase;

final class BackupConfigTest extends TestCase
{
    public function test_it_defines_the_default_manifest_path_and_stale_threshold(): void
    {
        $configuration = require base_path('config/backup.php');

        $this->assertSame(storage_path('app/private/backups/latest.json'), $configuration['manifest_path']);
        $this->assertSame(93_600, $configuration['stale_after_seconds']);
    }
}
