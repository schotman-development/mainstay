<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Mainstay\Auth\Gate;

/*
 | A guest asking for an admin path is sent to Mainstay's login. Not Laravel's
 | `auth`, whose redirect is the host's `login` route or the application-wide
 | redirectGuestsTo().
 |
 | The user signed in is put on the request, which is where the query layer
 | reads it from and the only place: see Gate::user().
 */
class Authenticate implements AuthenticatesRequests
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('mainstay')->user();

        if ($user === null) {
            return $request->expectsJson() ? abort(401) : redirect()->guest(route('mainstay.login'));
        }

        $request->attributes->set(Gate::USER, $user);

        return $next($request);
    }
}
