<?php

declare(strict_types=1);

namespace Tests\Unit\I18n;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Tests\TestCase;

final class SetLocaleMiddlewareTest extends TestCase
{
    public function test_query_lang_wins(): void
    {
        $middleware = new SetLocale();
        $request = Request::create('/app', 'GET', ['lang' => 'en']);
        $request->headers->set('Accept-Language', 'vi');

        $this->assertSame('en', $middleware->resolve($request));
    }

    public function test_accept_language_is_restricted_to_supported_locales(): void
    {
        $middleware = new SetLocale();
        $request = Request::create('/app', 'GET');
        $request->headers->set('Accept-Language', 'fr-FR,en-US;q=0.8');

        $this->assertSame('en', $middleware->resolve($request));
    }

    public function test_default_is_vietnamese_when_nothing_matches(): void
    {
        $middleware = new SetLocale();
        $request = Request::create('/app', 'GET');
        $request->headers->set('Accept-Language', 'ja,fr');

        $this->assertSame('vi', $middleware->resolve($request));
    }

    public function test_without_auth_header_or_query_the_default_is_vietnamese(): void
    {
        config(['javmeta.ui_locale' => 'en']);
        $middleware = new SetLocale();
        $request = Request::create('/app', 'GET', [], [], [], [
            'HTTP_ACCEPT_LANGUAGE' => '',
        ]);
        $request->headers->remove('Accept-Language');

        // Without an authenticated user the owner setting is ignored; default stays vi.
        $this->assertSame('vi', $middleware->resolve($request));
    }
}
