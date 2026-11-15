<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\Services\Settings\SettingsTranslator;
use Tests\TestCase;

final class SettingsTranslatorTest extends TestCase
{
    public function test_it_returns_english_labels_when_locale_is_en(): void
    {
        $this->app->setLocale('en');
        $translator = new SettingsTranslator();
        $this->assertSame('Interface language', $translator->label('ui.locale', 'Ngôn ngữ giao diện'));
        $this->assertSame('User interface', $translator->group('ui', 'Giao diện người dùng'));
    }

    public function test_it_falls_back_to_registry_wording_when_key_is_missing(): void
    {
        $this->app->setLocale('en');
        $translator = new SettingsTranslator();
        $this->assertSame('Fallback label', $translator->label('does.not.exist', 'Fallback label'));
    }
}
