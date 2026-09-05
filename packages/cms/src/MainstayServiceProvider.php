<?php

namespace Mainstay;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class MainstayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mainstay.php', 'mainstay');

        $this->app->singleton(Mainstay::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mainstay');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.path'),
            'middleware' => config('mainstay.middleware'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/admin.php');
        });

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.api.prefix'),
            'middleware' => config('mainstay.api.middleware'),
        ], function () {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mainstay.php' => config_path('mainstay.php'),
            ], 'mainstay-config');

            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/mainstay'),
            ], 'mainstay-assets');
        }
    }
}
