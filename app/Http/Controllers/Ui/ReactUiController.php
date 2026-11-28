<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Operations\LoginController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Serves the React UI (built into public/web by `npm run build` in ui/) at the root of the site. The page is static: it
 * reads the data through /api/v2 and keeps the signed-in user in the web session cookie, so no session starts here.
 */
final class ReactUiController
{
    /**
     * Page addresses of the UI are everything except the server routes below. The route pattern excludes them, so an unknown
     * path or a wrong method under them keeps answering as before (404, 405) and is never answered with the UI page.
     */
    public const PAGE_ADDRESS = '(?!(?:api|ui-api|ui-me|ui-admin|ops|horizon|files|node|storage|web|up|metrics)(?:/|$)).*';

    public function __invoke(Request $request): Response
    {
        $path = (string) config('javmeta.ui_index_path');
        abort_unless(is_file($path), 404, 'The UI has not been built (npm run build in ui/).');

        return response((string) file_get_contents($path), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-cache']);
    }

    /** The sign-in page: the command center host keeps its own page (it signs in to /ops), every other host gets the UI. */
    public function login(Request $request, LoginController $legacy): Response|View|RedirectResponse
    {
        $commandCenter = strtolower(trim((string) config('javmeta_domains.command_center_host', '')));

        return $commandCenter !== '' && strtolower($request->getHost()) === $commandCenter ? $legacy->show($request) : $this($request);
    }

    /** Any other page address of the UI (deep links, a reload): the same page, the router in the browser does the rest. */
    public function fallback(Request $request): Response
    {
        abort_if($request->expectsJson(), 404);

        return $this($request);
    }
}
