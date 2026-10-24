<?php

declare(strict_types=1);

namespace App\Http\Controllers\Operations;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController
{
    public function show(Request $request): View|RedirectResponse
    {
        if ($request->user()?->role === 'super_administrator') {
            return redirect()->route($this->homeRoute($request));
        }

        return view('operations.login')->with('email', 'soulevilx@gmail.com');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials)) {
            return back()->withErrors(['email' => 'Invalid email or password.'])->onlyInput('email');
        }

        if ($request->user()?->role !== 'super_administrator') {
            Auth::logout();

            return back()->withErrors(['email' => 'This account cannot access operations.'])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route($this->homeRoute($request)));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /** The command center host lands on the operations dashboard, every other host (the XCrawler UI) on the app. */
    private function homeRoute(Request $request): string
    {
        $commandCenter = strtolower(trim((string) config('javmeta_domains.command_center_host', '')));

        return $commandCenter !== '' && strtolower($request->getHost()) === $commandCenter ? 'operations.index' : 'ui.index';
    }
}
