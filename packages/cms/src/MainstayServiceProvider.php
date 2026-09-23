<?php

namespace Mainstay;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Mainstay\Console\SchemaCheckCommand;
use Mainstay\Console\SyncCommand;
use Mainstay\Content\Entry;
use Mainstay\Database\ContentSchema;
use Mainstay\Policies\EntryPolicy;
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
    }

    public function boot(): void
    {
        /* On the base class, so it is one policy per shape and a host's own
           policy for a type -- registered, discovered or #[UsePolicy] --
           still wins the way Laravel documents. */
        Gate::policy(Entry::class, EntryPolicy::class);

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
            $this->commands([SyncCommand::class, SchemaCheckCommand::class]);

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
