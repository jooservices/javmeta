<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use Tests\TestCase;

final class LocaleSettingsTest extends TestCase
{
    public function test_ui_locale_setting_is_registered_and_notification_text_has_no_locale_setting(): void
    {
        $definitions = config('javmeta_settings.definitions');
        $this->assertArrayHasKey('ui.locale', $definitions);
        $this->assertSame(['vi', 'en'], $definitions['ui.locale']['options']);
        $this->assertSame(['javmeta.ui_locale'], $definitions['ui.locale']['config']);

        // Notification message text stays the single editable English template; only its name and usage are translated.
        $this->assertArrayNotHasKey('notifications.locale', $definitions);
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
