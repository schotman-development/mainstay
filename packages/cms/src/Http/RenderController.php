<?php

namespace Mainstay\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Mainstay\Mainstay;

/*
 | The catch-all: a request's locale from its host and prefix, the entry its
 | path leads to there, and that entry's view. Everything a route the host
 | wrote does not answer ends here, and what leads to no entry is a 404.
 */
class RenderController
{
    public function __invoke(Request $request, Mainstay $mainstay): Response
    {
        [$locale, $uri] = $mainstay->resolve($request->getHost(), $request->path()) ?? abort(404);

        /* Before the lookup, so a 404 renders in the locale too, and every
           read the template makes is in it unless it asks for another. */
        App::setLocale($locale);

        $entry = $mainstay->findByUri($uri, locale: $locale) ?? abort(404);

        return response()->view($mainstay->template($entry), ['entry' => $entry]);
    }
}
