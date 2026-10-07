<?php

namespace Mainstay\Http;

use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Mainstay\Auth\User;

/*
 | A forgotten password, by a link mailed through the host's mailer. Asking
 | answers alike whether or not the email has an account, and whether or not
 | one was mailed a minute ago, so the form tells nobody which accounts exist.
 */
class PasswordController
{
    public function request(): View
    {
        return view('mainstay::forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'string', 'email']]);

        Password::broker('mainstay')->sendResetLink(['email' => Str::lower($request->input('email'))]);

        return back()->with('status', 'If that email has an account, a link to choose a new password is on its way.');
    }

    public function edit(Request $request, string $token): View
    {
        return view('mainstay::reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        /* A new remember token too, so a device remembered with the old
           password is signed out with it. */
        $status = Password::broker('mainstay')->reset(
            ['email' => Str::lower($request->input('email')), ...$request->only('password', 'password_confirmation', 'token')],
            fn (User $user, string $password) => $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save(),
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'This link has expired or been used. Ask for another.']);
        }

        return redirect()->route('mainstay.login')->with('status', 'Your password is changed. Sign in with it.');
    }
}
