<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class UiGuestModeConfigTest extends TestCase
{
    public function test_login_is_required_by_default_and_guest_rate_limit_is_a_database_setting(): void
    {
        $root = dirname(__DIR__, 3);
        $config = require $root . '/config/javmeta.php';
        $registry = require $root . '/config/javmeta_settings.php';
        $definition = $registry['definitions']['ui_guest.rate_limit_per_minute'];

        $this->assertTrue($config['ui_login_required']);
        $this->assertSame(120, $config['ui_guest_rate_limit_per_minute']);
        $this->assertSame('ui_security', $definition['group']);
        $this->assertSame('int', $definition['type']);
        $this->assertSame(['javmeta.ui_guest_rate_limit_per_minute'], $definition['config']);
        $this->assertSame('Giới hạn yêu cầu giao diện khách mỗi phút theo IP', $definition['label']);
        $this->assertSame('Số yêu cầu tối đa mỗi phút từ một địa chỉ IP đến cầu nối giao diện công khai chỉ đọc.', $definition['description']);
    }
}
