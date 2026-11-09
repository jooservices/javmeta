<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use Tests\TestCase;

final class LocaleSettingsTest extends TestCase
{
    public function test_ui_and_notification_locale_settings_are_registered(): void
    {
        $definitions = config('javmeta_settings.definitions');
        $this->assertArrayHasKey('ui.locale', $definitions);
        $this->assertSame(['vi', 'en'], $definitions['ui.locale']['options']);
        $this->assertSame(['javmeta.ui_locale'], $definitions['ui.locale']['config']);

        $this->assertArrayHasKey('notifications.locale', $definitions);
        $this->assertSame(['en', 'vi'], $definitions['notifications.locale']['options']);
        $this->assertSame(['javmeta_notifications.locale'], $definitions['notifications.locale']['config']);
        $this->assertSame('en', config('javmeta_notifications.locale'));
        $this->assertSame('vi', config('javmeta.ui_locale'));
    }

    public function test_login_page_follows_accept_language(): void
    {
        $vi = $this->get('/login', ['Accept-Language' => 'vi']);
        $vi->assertOk();
        $vi->assertSee('Đăng nhập', false);
        $vi->assertSee('lang="vi"', false);

        $en = $this->get('/login', ['Accept-Language' => 'en']);
        $en->assertOk();
        $en->assertSee('Sign in', false);
        $en->assertSee('lang="en"', false);
    }

    public function test_query_lang_overrides_accept_language_on_login(): void
    {
        $response = $this->get('/login?lang=en', ['Accept-Language' => 'vi']);
        $response->assertOk();
        $response->assertSee('Sign in', false);
        $response->assertSee('lang="en"', false);
    }
}
