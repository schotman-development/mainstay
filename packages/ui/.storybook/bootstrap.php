<?php

/*
 | Run once per render, before the story's template. Its whole job is to leave
 | a booted Laravel behind so the Blade in resources/views compiles through the
 | same engine the admin will use at request time.
 |
 | Testbench rather than a hand-assembled container: the component tag compiler
 | that turns <x-mainstay::button> into markup wants config, an event
 | dispatcher, a compiled path and package discovery, and a workshop that
 | approximates four of those is a workshop that renders something the admin
 | does not.
 */

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\View\Factory as ViewFactory;
use Orchestra\Testbench\Foundation\Application;

require_once __DIR__.'/../../../vendor/autoload.php';

$app = Application::create(basePath: dirname(__DIR__, 3).'/vendor/orchestra/testbench-core/laravel');

Container::setInstance($app);
Facade::setFacadeApplication($app);

/*
 | The Blade behind a story that shows several components at once -- three
 | buttons in a row, the size ladder, a form -- lives here rather than in
 | resources/views, so that theme.css can scan the components without having to
 | exclude the workshop from its own directory. Its own namespace, so the
 | adapter still finds a view name for it and <x-mainstay::...> inside it keeps
 | resolving.
 */
$app->make(ViewFactory::class)->addNamespace('stories', __DIR__.'/../stories/blade');
