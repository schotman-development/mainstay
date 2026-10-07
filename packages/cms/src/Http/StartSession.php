<?php

namespace Mainstay\Http;

use Closure;
use Illuminate\Session\Middleware\StartSession as LaravelStartSession;
use Illuminate\Support\Facades\Auth;

/*
 | The admin's session, under a cookie of its own, so signing in to the host's
 | site signs nobody in to Mainstay and a visitor's session is never an
 | editor's. Everything else about it -- driver, lifetime, domain -- is the
 | host's `session` config.
 |
 | Laravel names its one session store once, from `session.cookie`, and the
 | guard writes to that same store, so this renames it for the request and
 | back after rather than starting a second one. Mainstay's guard is the
 | request's default for as long: nothing asks the host's guard during an
 | admin request, the database session driver recording who a session
 | belongs to included, and Laravel's AuthenticateSession checks Mainstay's.
 */
class StartSession extends LaravelStartSession
{
    public const COOKIE = 'mainstay_session';

    public function handle($request, Closure $next)
    {
        $store = $this->manager->driver();
        $name = $store->getName();
        $guard = Auth::getDefaultDriver();

        $store->setName(self::COOKIE);
        Auth::shouldUse('mainstay');

        try {
            return parent::handle($request, $next);
        } finally {
            $store->setName($name);
            Auth::shouldUse($guard);
        }
    }
}
