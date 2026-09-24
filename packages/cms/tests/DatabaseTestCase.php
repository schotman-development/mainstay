<?php

namespace Mainstay\Tests;

use Illuminate\Support\Facades\DB;
use Mainstay\Mainstay;
use Mainstay\MainstayServiceProvider;
use Orchestra\Testbench\TestCase;
use RuntimeException;

/*
 | SQLite by default. MAINSTAY_TEST_DB=pgsql or mysql runs the same tests
 | against a server on DB_HOST/DB_PORT, since the scratch comparison, the
 | Postgres cast and MySQL's foreign key rules only show up on the real
 | driver.
 |
 | SQLite in a file rather than in memory, so a second connection can open the
 | same database: the tests of a caller's own transaction write beside it. The
 | default rollback journal, as a Laravel app has it, and not WAL, whose -wal
 | file outlives the truncation db:wipe empties a SQLite file with. No sync to
 | disk, which a test database has no crash to survive and which costs the
 | suite ten times its run on a real disk. A short busy timeout, since the
 | write beside a caller's transaction is one SQLite refuses. One file per
 | process, emptied by migrate:fresh for every test and removed at exit, and
 | one a killed run left behind removed by the next.
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
            ? ['driver' => 'sqlite', 'database' => self::sqlite(), 'foreign_key_constraints' => true, 'synchronous' => 'off', 'busy_timeout' => 100]
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

    /* Every connection closed before the next test opens its own, since a
       file outlives the application each test builds: a test that fails with
       a transaction open would otherwise hold the lock every later test's
       migrate:fresh waits on. */
    protected function tearDown(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    private static function sqlite(): string
    {
        static $file;

        if ($file === null) {
            /* A run killed before its shutdown left its file; a process that
               is gone no longer needs it. Only where /proc says so. */
            if (is_dir('/proc')) {
                foreach (glob(sys_get_temp_dir().'/mainstay-test-*.sqlite*') ?: [] as $left) {
                    if (preg_match('/-(\d+)\.sqlite(-journal)?$/', $left, $pid) && ! is_dir("/proc/{$pid[1]}")) {
                        @unlink($left);
                    }
                }
            }

            $file = sys_get_temp_dir().'/mainstay-test-'.getmypid().'.sqlite';
            touch($file);
            register_shutdown_function(fn () => array_map(fn (string $path) => @unlink($path), [$file, "{$file}-journal"]));
        }

        return $file;
    }

    protected function declare(string ...$types): void
    {
        $this->app->instance(Mainstay::class, $mainstay = new Mainstay);
        $mainstay->types($types);
    }
}
