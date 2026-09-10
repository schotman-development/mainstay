<?php

namespace Mainstay\Ui;

use Illuminate\Support\ServiceProvider;

class UiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        /*
         | The same `mainstay` hint the CMS package registers, not one of its
         | own. A view namespace holds a list of paths rather than a single one,
         | so both packages adding to it is supported and the admin gets
         | <x-mainstay::button> beside its own <x-mainstay::...> screens instead
         | of a second prefix to remember. The two never collide: this package
         | only ever ships components/, the CMS only ever ships screens.
         */
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mainstay');
    }
}
