<?php

namespace Mainstay\Tests;

use Mainstay\Mainstay;
use Mainstay\MainstayServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/*
 | SQLite in memory by default. MAINSTAY_TEST_DB=pgsql or mysql runs the same
 | tests against a server on DB_HOST/DB_PORT, since the scratch comparison,
 | the Postgres cast and MySQL's foreign key rules only show up on the real
 | driver.
 |
 | migrate:fresh rather than a trait: RefreshDatabase's transaction is one
 | SQLite cannot toggle foreign keys inside to rebuild a table, and
 | DatabaseMigrations rolls `sites` back from under the content tables sync
 | built, which still reference it. fresh drops those too.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->run();
    }

    protected function getPackageProviders($app): array
    {
        return [MainstayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = env('MAINSTAY_TEST_DB', 'sqlite');

        /* The suite runs migrate:fresh. Left to the driver's default port, it
           runs it on whatever server answers there. */
        if ($driver !== 'sqlite' && blank(env('DB_PORT'))) {
            throw new RuntimeException("MAINSTAY_TEST_DB={$driver} needs DB_PORT: the suite empties the database it reaches.");
        }

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]
            : [
                'driver' => $driver,
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT'),
                'database' => env('DB_DATABASE', 'mainstay'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
                /* Only sqlsrv reads this, and only because ODBC 18 encrypts
                   by default and the server in CI signs its own certificate.
                   The others ignore a key they have no use for. */
                'trust_server_certificate' => true,
            ]);
        $app['config']->set('mainstay.schema.sync', true);
    }

    protected function declare(string ...$types): void
    {
        $this->app->instance(Mainstay::class, $mainstay = new Mainstay);
        $mainstay->types($types);
    }
}
