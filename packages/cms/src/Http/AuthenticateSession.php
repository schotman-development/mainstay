<?php

namespace Mainstay\Http;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession as LaravelAuthenticateSession;

/*
 | Laravel's, which keeps the password's hash in the session and signs a
 | session out once it no longer matches, so a changed password ends every
 | other session. Sent to Mainstay's login rather than the host's.
 */
class AuthenticateSession extends LaravelAuthenticateSession
{
    protected function redirectTo(Request $request)
    {
        return route('mainstay.login');
    }
}
