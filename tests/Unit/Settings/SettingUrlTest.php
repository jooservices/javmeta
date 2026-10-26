<?php

declare(strict_types=1);

namespace Tests\Unit\Settings;

use App\Services\Settings\SettingUrl;
use PHPUnit\Framework\TestCase;

final class SettingUrlTest extends TestCase
{
    public function test_redaction_preserves_the_destination_without_url_credentials(): void
    {
        self::assertSame('https://example.test:8443/base', SettingUrl::redact('https://user:p%40ss@example.test:8443/base?access_token=sensitive#fragment'));
    }

    public function test_malformed_urls_never_echo_the_original_value(): void
    {
        self::assertSame('[invalid URL]', SettingUrl::redact('https://user:secret@/missing?token=sensitive'));
        self::assertSame('[invalid URL]', SettingUrl::redact('not-a-url?token=sensitive'));
    }

    public function test_empty_optional_urls_stay_empty(): void
    {
        self::assertSame('', SettingUrl::redact(''));
    }

    public function test_each_url_in_a_log_message_is_sanitized(): void
    {
        self::assertSame('Failed https://first.test/path then http://second.test:8080/api', SettingUrl::redactText('Failed https://user:secret@first.test/path?token=sensitive then http://second.test:8080/api?password=sensitive'));
    }

    public function test_quotes_inside_url_secrets_do_not_stop_redaction(): void
    {
        self::assertSame('Failed https://example.test/base', SettingUrl::redactText("Failed https://o'brien:private-pass@example.test/base?token='private-query'"));
        self::assertSame('Failed https://example.test/base', SettingUrl::redactText('Failed https://example.test/base?token="private-query"'));
    }
}
