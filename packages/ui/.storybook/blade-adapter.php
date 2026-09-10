<?php

/*
 | Terminal middleware for the `*.blade.php` imports: it renders the view and
 | never calls $next, because there is no PHP callable behind a Blade template
 | for the default runner to invoke.
 |
 | The file is turned back into a namespaced view name rather than rendered
 | from its path, so that <x-mainstay::icon> inside a component still resolves
 | -- ViewFactory::file() compiles a template with no namespace context, and a
 | component that includes another one would fail to find it.
 |
 | Args go in as an attribute bag rather than as plain view variables, which is
 | the whole difference between rendering the file and rendering the component.
 | @props pulls the declared ones back out as variables and leaves the rest on
 | $attributes, so a story can hand over `disabled`, `href` or `aria-label` and
 | reach the markup exactly as a <x-mainstay::button disabled> in the admin
 | would. Rendering it as a view instead drops all of them silently.
 */

use Illuminate\Container\Container;
use Illuminate\Support\HtmlString;
use Illuminate\View\ComponentAttributeBag;
use Illuminate\View\Factory as ViewFactory;

return static function (array $context, callable $next): array {
    $factory = Container::getInstance()->make(ViewFactory::class);
    $args = $context['templateArgs'] ?? [];
    $file = realpath((string) ($context['file'] ?? '')) ?: (string) ($context['file'] ?? '');

    /* Args are JSON off the browser, so the slot arrives as a string. Marked
       as HTML rather than escaped: a story that wants an icon beside its label
       is passing markup, and {{ $slot }} would otherwise print the tags. */
    $slot = new HtmlString((string) ($args['slot'] ?? ''));
    unset($args['slot']);

    $data = ['slot' => $slot, 'attributes' => new ComponentAttributeBag($args)];

    foreach ($factory->getFinder()->getHints() as $namespace => $paths) {
        foreach ($paths as $path) {
            $root = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

            if (! str_starts_with($file, $root)) {
                continue;
            }

            $view = preg_replace('/\.blade\.php$/', '', str_replace(DIRECTORY_SEPARATOR, '.', substr($file, strlen($root))));

            return ['html' => $factory->make($namespace.'::'.$view, $data)->render()];
        }
    }

    return ['html' => $factory->file($file, $data)->render()];
};
