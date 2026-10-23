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
            return redirect()->route('operations.index');
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

        return redirect()->intended(route('operations.index'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
