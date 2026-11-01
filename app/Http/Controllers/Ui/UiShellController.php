<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use Illuminate\Http\Response;

/**
 * The XCrawler UI (single page, hash routes): the page itself needs the owner's session, its assets under /ui are static.
 */
final class UiShellController
{
    public function __invoke(\Illuminate\Http\Request $request): Response
    {
        $path = public_path('ui/index.html');
        abort_unless(is_file($path), 404);

        // The page is static; the logout form needs the session's CSRF token, so it is added when the shell is served.
        // The operations dashboard lives on its own host (the command center); the UI links to it from this tag.
        $host = trim((string) config('javmeta_domains.command_center_host', ''));
        $guest = (bool) $request->attributes->get('javmeta.guest_mode', false);
        $commandCenter = ! $guest && $host !== '' ? '<meta name="command-center-url" content="' . e($request->getScheme() . '://' . $host . '/ops') . '">' : '';
        $meta = $guest
            ? '<meta name="robots" content="noindex"><meta name="ui-guest-mode" content="true">'
            : '<meta name="csrf-token" content="' . csrf_token() . '"><meta name="ui-guest-mode" content="false">';
        $html = str_replace('</head>', $meta . $commandCenter . '</head>', (string) file_get_contents($path));
        $html = str_replace('<body>', '<body data-guest-mode="' . ($guest ? 'true' : 'false') . '">', $html);
        if ($guest) {
            $html = str_replace(
                '<div class="app">',
                '<div class="app"><div class="guest-banner" role="note">Chế độ khách chỉ cho phép xem nội dung công khai. <a href="/login">Đăng nhập để dùng các tính năng cá nhân và quản trị</a>.</div>',
                $html,
            );
        }

        $response = response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => $guest ? 'no-store' : 'no-store, private',
        ]);

        if ($guest) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
