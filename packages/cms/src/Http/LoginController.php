<?php

namespace Mainstay\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mainstay\Auth\User;

/*
 | Signing in and out of the admin. A wrong password and an unknown email are
 | one answer, so the form cannot tell which accounts exist; five failures for
 | an email from one address lock it for a minute.
 */
class LoginController
{
    private const ATTEMPTS = 5;

    public function show(): View|RedirectResponse
    {
        if (Auth::guard('mainstay')->check()) {
            return redirect()->route('mainstay.admin');
        }

        /* No page makes the first account, so a new server has none that
           whoever reaches it first can claim. */
        return view('mainstay::login', ['empty' => ! User::query()->exists()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email'], 'password' => ['required', 'string']]);
        $credentials['email'] = Str::lower($credentials['email']);
        $key = 'mainstay-login:'.$credentials['email'].'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => sprintf('Too many attempts. Try again in %d seconds.', RateLimiter::availableIn($key)),
            ]);
        }

        if (! Auth::guard('mainstay')->attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => 'That email and password do not match an account.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        /* What AuthenticateSession compares on every admin request, kept from
           the start: it would only store it on the first, and a session that
           signed in and waited would survive a password reset. */
        $guard = Auth::guard('mainstay');
        $request->session()->put('password_hash_mainstay', $guard->hashPasswordForCookie($guard->user()->getAuthPassword()));

        return redirect()->intended(route('mainstay.admin'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('mainstay')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('mainstay.login');
    }
}
