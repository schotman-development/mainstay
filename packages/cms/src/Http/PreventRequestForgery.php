<?php

namespace Mainstay\Http;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as LaravelPreventRequestForgery;

/*
 | CSRF, checked against the admin's session. No XSRF-TOKEN cookie: it has one
 | name and one path for the host's session and Mainstay's, so each would
 | overwrite the other's. The admin's forms carry the token instead.
 */
class PreventRequestForgery extends LaravelPreventRequestForgery
{
    protected $addHttpCookie = false;
}
