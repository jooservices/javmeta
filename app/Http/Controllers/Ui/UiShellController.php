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
        $commandCenter = $host !== '' ? '<meta name="command-center-url" content="' . e($request->getScheme() . '://' . $host . '/ops') . '">' : '';
        $html = str_replace('</head>', '<meta name="csrf-token" content="' . csrf_token() . '">' . $commandCenter . '</head>', (string) file_get_contents($path));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
