<?php

namespace Mainstay;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mainstay\Auth\User;
use Mainstay\Console\ReprocessCommand;
use Mainstay\Console\SchemaCheckCommand;
use Mainstay\Console\SyncCommand;
use Mainstay\Console\UserCommand;
use Mainstay\Database\ContentSchema;
use Mainstay\Http\PreventRequestForgery;
use Mainstay\Http\RenderController;
use Mainstay\Http\StartSession;
use Mainstay\Ui\UiServiceProvider;
use Throwable;

class MainstayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mainstay.php', 'mainstay');

        /*
         | The design system, explicitly rather than through package discovery.
         | The admin's views do not render without it, and a host that turns
         | discovery off for the package would otherwise get a 500 rather than a
         | missing style. Registering twice is a no-op, so an app that lists it
         | itself loses nothing.
         */
        $this->app->register(UiServiceProvider::class);

        $this->app->singleton(Mainstay::class);

        /*
         | Mainstay's own guard, user provider and password broker, beside the
         | host's and named apart from them, unless the host has defined one by
         | the name. The broker's config written out: only Laravel's skeleton
         | has defaults for it, and a missing throttle is none at all.
         */
        foreach ([
            'auth.guards.mainstay' => ['driver' => 'session', 'provider' => 'mainstay'],
            'auth.providers.mainstay' => ['driver' => 'eloquent', 'model' => User::class],
            'auth.passwords.mainstay' => ['provider' => 'mainstay', 'table' => 'mainstay_password_reset_tokens', 'expire' => 60, 'throttle' => 60],
        ] as $key => $value) {
            if (! $this->app['config']->has($key)) {
                $this->app['config']->set($key, $value);
            }
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'mainstay');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        /*
         | The admin's own stack rather than the host's `web` group, whose
         | session is a visitor's on a site with members: cookies, a session
         | under Mainstay's cookie, errors, CSRF. What the host adds in
         | `mainstay.middleware` runs after it.
         */
        Route::middlewareGroup('mainstay', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
        ]);

        Route::group([
            'domain' => config('mainstay.domain'),
            'prefix' => config('mainstay.path'),
            'middleware' => ['mainstay', ...config('mainstay.middleware', [])],
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

        /*
         | Public pages. A fallback, which Laravel matches after every other
         | route whatever order they were registered in, so a route the host
         | writes always wins. Under a parameter of its own: Route::fallback()
         | files every fallback under one path, and the host's, registered
         | after this, would replace it. The host's is the one that never runs,
         | unless the host turns public pages off.
         |
         | The defaults as well as the config's, since a host's config
         | replaces the package's `site` whole and may leave a key out.
         */
        if (config('mainstay.site.enabled', true)) {
            Route::get('{mainstayPath}', RenderController::class)
                ->where('mainstayPath', '.*')
                ->fallback()
                ->middleware(config('mainstay.site.middleware', ['web']))
                ->name('mainstay.site');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([SyncCommand::class, SchemaCheckCommand::class, ReprocessCommand::class, UserCommand::class]);

            Event::listen(fn (CommandStarting $event) => $this->warnAboutSync($event));

            $this->publishes([
                __DIR__.'/../config/mainstay.php' => config_path('mainstay.php'),
            ], 'mainstay-config');

            $this->publishes([
                __DIR__.'/../dist' => public_path('vendor/mainstay'),
            ], 'mainstay-assets');
        }
    }

    /*
     | Payload's warning, for the same accident: hand-written migrations run
     | against a database sync already built, where the first one that creates
     | a content table collides with the table sync made. A warning rather than
     | a refusal, because a migration that touches nothing sync built is fine.
     |
     | Quiet when the marker cannot be read. migrate reports a missing database
     | better than a listener in front of it would.
     */
    public function warnAboutSync(CommandStarting $event): void
    {
        if ($event->command !== 'migrate') {
            return;
        }

        try {
            $repository = $this->app->make('migration.repository');
            $synced = $repository->repositoryExists() && in_array(ContentSchema::MARKER, $repository->getRan(), true);
        } catch (Throwable) {
            return;
        }

        if ($synced) {
            $event->output->writeln('<comment>mainstay:sync has altered this database. A migration that creates or alters a content table will collide with what sync built; migrate:fresh starts again from the migrations alone.</comment>');
        }
    }
}
