<?php

namespace Mainstay\Tests;

use BadMethodCallException;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Mainstay\Database\ContentSchema;
use Mainstay\Tests\Fixtures\Accented\Article as AccentedArticle;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Broken\Collided;
use Mainstay\Tests\Fixtures\Broken\Reserved;
use Mainstay\Tests\Fixtures\Broken\Uris;
use Mainstay\Tests\Fixtures\Coded\Article as CodedArticle;
use Mainstay\Tests\Fixtures\Moody\Article as MoodyArticle;
use Mainstay\Tests\Fixtures\Recoded\Article as RecodedArticle;
use Mainstay\Tests\Fixtures\Revised\Article as RevisedArticle;
use Mainstay\Tests\Fixtures\Setted\Article as SettedArticle;
use Mainstay\Tests\Fixtures\Tiered\Article as TieredArticle;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 | The phase 2 check: a declared type synced into sqlite, then a property
 | renamed and the drift check failing.
 */
class SchemaTest extends DatabaseTestCase
{
    private function insertArticle(array $values = []): int
    {
        return DB::table('article')->insertGetId([
            'site_id' => 1, 'title' => 'Kept', 'reading_minutes' => 3, 'featured' => false, 'status' => 'live', ...$values,
        ]);
    }

    #[Test]
    public function it_seeds_one_site_that_matches_any_host(): void
    {
        $this->assertEquals(
            [(object) ['id' => 1, 'handle' => 'default', 'name' => config('app.name'), 'hostname' => null]],
            DB::table('sites')->get()->all(),
        );
    }

    #[Test]
    public function the_uri_lookup_holds_one_path_per_site_and_locale(): void
    {
        $row = ['site_id' => 1, 'locale' => 'en', 'uri' => '/blog/hello', 'type' => 'article', 'entry_id' => 1];

        $second = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);

        /* An entry belongs to one site, so the same path on the second site
           is another entry's. */
        DB::table('uris')->insert($row);
        DB::table('uris')->insert(['locale' => 'nl'] + $row);
        DB::table('uris')->insert(['site_id' => $second, 'entry_id' => 2] + $row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('uris')->insert(['entry_id' => 3] + $row);
    }

    #[Test]
    public function one_path_per_entry_and_locale_arrives_over_rows_that_break_it(): void
    {
        $migration = require __DIR__.'/../database/migrations/2026_09_24_000000_hold_one_path_per_entry_and_locale.php';
        $migration->down();

        $row = ['site_id' => 1, 'locale' => 'en', 'type' => 'article', 'entry_id' => 1];
        $first = DB::table('uris')->insertGetId(['uri' => '/blog/first'] + $row);
        DB::table('uris')->insert(['uri' => '/blog/second'] + $row);
        DB::table('uris')->insert(['uri' => '/blog/other', 'entry_id' => 2] + $row);

        $migration->up();

        $this->assertSame([$first, '/blog/first'], [(int) DB::table('uris')->where('entry_id', 1)->value('id'), DB::table('uris')->where('entry_id', 1)->value('uri')]);
        $this->assertSame(2, DB::table('uris')->count());
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('uris')->insert(['uri' => '/blog/third'] + $row);
    }

    #[Test]
    public function it_syncs_a_declared_type_into_a_main_table_and_a_locales_sibling(): void
    {
        $this->declare(Article::class);

        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->assertSame(
            ['id', 'site_id', 'title', 'reading_minutes', 'featured', 'published_at', 'status', 'owner_id', 'created_at', 'updated_at', 'deleted_at'],
            Schema::getColumnListing('article'),
        );
        $this->assertSame(
            ['id', 'parent_id', 'site_id', 'locale', 'summary', 'deleted_at'],
            Schema::getColumnListing('article_locales'),
        );
        $this->assertFalse(collect(Schema::getColumns('article'))->firstWhere('name', 'title')['nullable']);
        $this->assertTrue(collect(Schema::getColumns('article'))->firstWhere('name', 'published_at')['nullable']);

        $this->assertSame([], app(ContentSchema::class)->diff());
        $this->artisan('mainstay:schema:check')->assertSuccessful();
        $this->assertEmpty(array_filter(Schema::getTableListing(schemaQualified: false), fn (string $name) => str_starts_with($name, 'mainstay_scratch_')));
    }

    #[Test]
    public function the_drift_check_fails_once_a_property_is_renamed_or_retyped(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->declare(RevisedArticle::class);

        /* Artisan::output() rather than expectsOutputToContain(): the list is
           one write, and each expectation consumes the write it matched. */
        $this->assertSame(1, Artisan::call('mainstay:schema:check'));
        $output = Artisan::output();
        $this->assertStringContainsString('article.headline: declared, and the column does not exist', $output);
        $this->assertStringContainsString('article.title: in the database, and not declared', $output);
        /* The type names are the driver's own, so only the column is asserted. */
        $this->assertMatchesRegularExpression('/article\.reading_minutes: \w+.*not null in the database, declared [\w ]+.*not null/', $output);
    }

    /*
     | A migration that writes the columns and forgets the index leaves two
     | rows free to claim one locale, which is the whole reason the sibling
     | table is keyed the way it is. The check compares keys so that CI sees
     | it, and sync writes them back rather than only reporting them.
     */
    #[Test]
    public function the_drift_check_fails_once_a_declared_key_is_gone_and_sync_puts_it_back(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        /* The key before the index it leans on: MySQL indexes a foreign key's
           columns, takes the unique index as that index because parent_id is
           its first column, and refuses to drop an index a key still needs. */
        Schema::table('article_locales', function ($table) {
            $table->dropForeign(['parent_id']);
            $table->dropUnique(['parent_id', 'locale']);
        });

        $this->assertSame(1, Artisan::call('mainstay:schema:check'));
        $output = Artisan::output();
        $this->assertStringContainsString('article_locales: declared unique on (parent_id, locale)', $output);
        $this->assertStringContainsString('article_locales: declared a foreign key on (parent_id) referencing article.id', $output);

        /* Gone with the index: the same locale twice, and a parent that is not
           there at all. */
        $parent = $this->insertArticle();
        $row = ['parent_id' => $parent, 'site_id' => 1, 'locale' => 'en'];
        DB::table('article_locales')->insert($row);
        DB::table('article_locales')->insert($row);
        DB::table('article_locales')->insert(['parent_id' => $parent + 99] + $row);
        DB::table('article_locales')->delete();

        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->assertSame([], app(ContentSchema::class)->diff());

        DB::table('article_locales')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('article_locales')->insert($row);
    }

    /*
     | A prefixed connection reports its foreign keys against the table it
     | really wrote, and the declaration names the one Laravel prefixes on the
     | way there. Compared as reported, every key reads as missing: the check
     | fails on a database that matches, and sync writes the same keys again
     | on every run.
     */
    #[Test]
    public function keys_are_compared_on_a_connection_with_a_table_prefix(): void
    {
        /*
         | Emptied on the way in and out rather than left to migrate:fresh,
         | which drops what the prefix in force can see. An index is named
         | after the table without its prefix and lives in the schema rather
         | than in the table, so the unprefixed run's `sites_handle_unique` is
         | still there for `ms_sites` to collide with -- on every driver, since
         | each one's database, SQLite's file included, outlives the test.
         */
        $this->artisan('db:wipe')->run();

        config()->set('database.connections.testing.prefix', 'ms_');
        DB::purge('testing');

        try {
            $this->artisan('migrate:fresh')->run();

            $this->declare(Article::class);
            $this->artisan('mainstay:sync')->assertSuccessful();

            $this->assertSame([], app(ContentSchema::class)->diff());
            $this->artisan('mainstay:schema:check')->assertSuccessful();
        } finally {
            $this->artisan('db:wipe')->run();
        }
    }

    #[Test]
    public function sync_asks_before_dropping_and_changes_nothing_when_declined(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();

        $this->declare(RevisedArticle::class);

        $this->artisan('mainstay:sync')
            ->expectsConfirmation('Drop article.title, and the data in them?', 'no')
            ->assertFailed();

        $this->assertTrue(Schema::hasColumn('article', 'title'));
        $this->assertFalse(Schema::hasColumn('article', 'headline'));

        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('article', 'title'));
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_run_without_interaction_drops_nothing(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->declare(RevisedArticle::class);

        $this->assertSame(1, Artisan::call('mainstay:sync', ['--no-interaction' => true]));
        $this->assertTrue(Schema::hasColumn('article', 'title'));
        $this->assertFalse(Schema::hasColumn('article', 'headline'));
    }

    #[Test]
    public function sync_adds_and_tightens_required_columns_on_a_table_holding_content(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle(['published_at' => null]);

        $this->declare(RevisedArticle::class);
        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $row = DB::table('article')->first();

        $this->assertSame('', $row->headline, 'A text field has an empty value of its own.');
        $this->assertSame('plain', $row->tone, 'A select takes its first option.');
        $this->assertNotNull($row->reviewed_on, 'A date has no empty value, and takes the day the column arrived.');
        $this->assertNotNull($row->published_at, 'A nullable column made required is filled where it held null.');
        $this->assertSame('live', $row->status, 'Nothing already stored is touched.');

        $columns = collect(Schema::getColumns('article'))->keyBy('name');

        foreach (['headline', 'tone', 'reviewed_on', 'published_at'] as $column) {
            $this->assertFalse($columns[$column]['nullable'], "{$column} ends NOT NULL, as declared.");
        }

        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_gives_a_missing_site_id_the_first_site(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();
        Schema::table('article', fn ($table) => $table->dropForeign(['site_id']));
        Schema::table('article', fn ($table) => $table->dropColumn('site_id'));

        $this->artisan('mainstay:sync --force')->assertSuccessful();

        /* Equals rather than same: PDO hands a bigint back as a string on
           SQL Server and as an int on the rest, and which it is is not what
           this is asking. */
        $this->assertEquals(1, DB::table('article')->value('site_id'));
        $this->assertSame(['site_id'], collect(Schema::getForeignKeys('article'))->pluck('columns')->flatten()->all(), 'The key comes back with the column.');
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_fills_a_field_with_no_empty_value_from_its_column_type(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();

        $this->declare(AccentedArticle::class);
        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $this->assertSame('', DB::table('article')->value('accent'), 'ColorPicker has no empty value, and its column is a string.');
        $this->assertFalse(collect(Schema::getColumns('article'))->firstWhere('name', 'accent')['nullable']);
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_fills_enum_and_year_columns_from_their_type(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();

        $this->declare(TieredArticle::class);
        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $row = DB::table('article')->first();

        $this->assertSame('calm', $row->mood, 'An enum takes its first allowed value.');
        $this->assertEquals(now()->year, $row->vintage, 'A year takes the current one.');
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_makes_an_optional_enum_required_on_a_table_holding_content(): void
    {
        $this->declare(MoodyArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle(['mood' => null]);

        $this->declare(TieredArticle::class);
        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $this->assertSame('calm', DB::table('article')->value('mood'));
        $this->assertFalse(collect(Schema::getColumns('article'))->firstWhere('name', 'mood')['nullable']);
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_fills_a_set_column_with_its_first_member(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();

        $this->declare(SettedArticle::class);

        /* Only MySQL and MariaDB have a set column. Anywhere else Laravel's
           grammar has no type to write, and sync stops at the comparison,
           before it has altered anything. */
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertThrows(fn () => Artisan::call('mainstay:sync', ['--force' => true]), BadMethodCallException::class, 'typeSet does not exist');
            $this->assertFalse(Schema::hasColumn('article', 'sections'));
            $this->assertSame('Kept', DB::table('article')->value('title'));
        } else {
            $this->artisan('mainstay:sync --force')->assertSuccessful();

            $this->assertSame('news', DB::table('article')->value('sections'));
            $this->assertSame([], app(ContentSchema::class)->diff());
        }
    }

    #[Test]
    public function sync_casts_stored_values_across_a_type_change(): void
    {
        $this->declare(CodedArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('article')->insert(['site_id' => 1, 'code' => '42']);

        /* Without --force: '42' survives the cast, so nothing is asked. */
        $this->declare(RecodedArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->assertEquals(42, DB::table('article')->value('code'));
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_asks_before_a_type_change_that_loses_data_and_changes_nothing_when_declined(): void
    {
        $this->declare(CodedArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('article')->insert([['site_id' => 1, 'code' => '42'], ['site_id' => 1, 'code' => '007']]);
        $type = collect(Schema::getColumns('article'))->firstWhere('name', 'code')['type'];

        $this->declare(RecodedArticle::class);

        $this->artisan('mainstay:sync')
            ->expectsConfirmation('Retype article.code, and change values stored in them that the new type cannot hold?', 'no')
            ->assertFailed();

        $this->assertSame(1, Artisan::call('mainstay:sync', ['--no-interaction' => true]), 'A type change is never applied unasked.');

        $this->assertSame($type, collect(Schema::getColumns('article'))->firstWhere('name', 'code')['type']);
        $this->assertSame(['42', '007'], DB::table('article')->orderBy('id')->pluck('code')->all());
        $this->assertEmpty(array_filter(Schema::getTableListing(schemaQualified: false), fn (string $name) => str_starts_with($name, 'mainstay_scratch_')));
    }

    #[Test]
    public function every_driver_laravel_connects_to_has_a_comparison_of_its_own(): void
    {
        $schema = app(ContentSchema::class);
        $comparison = fn (string $driver) => (fn () => $this->comparison($driver))->call($schema);

        foreach (['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'] as $driver) {
            [$text, $differs] = $comparison($driver);

            $this->assertNotSame('', $text, "{$driver} has nothing to cast a key to.");
            $this->assertStringContainsString('l.code', $differs('l.code', 's.code', 'integer'), "{$driver} does not read the live value.");
            $this->assertStringContainsString('s.code', $differs('l.code', 's.code', 'integer'), "{$driver} does not read the converted value.");
        }

        /* Pinned as well as run, since CI reaches SQL Server on one job and
           this test on every driver: INTERSECT for a null-safe comparison, a
           binary collation against a case-insensitive default, and no LIMIT,
           which the builder spells as TOP for it. */
        [$text, $differs] = $comparison('sqlsrv');

        $this->assertSame('varchar(max)', $text);
        $this->assertSame(
            'not exists (select cast(l.code as varchar(max)) collate Latin1_General_BIN2 intersect select cast(s.code as varchar(max)) collate Latin1_General_BIN2)',
            $differs('l.code', 's.code', 'integer'),
        );

        /* A float rendered by CAST stops at six significant digits, which is
           two rounded-apart values reading as one and the question never
           being asked. Style 3 renders the seventeen that round-trip. */
        $this->assertSame(
            'not exists (select convert(varchar(max), cast(l.rate as float), 3) intersect select convert(varchar(max), cast(s.rate as float), 3))',
            $differs('l.rate', 's.rate', 'float'),
        );
        $this->assertStringContainsString('convert(varchar(max), cast(l.rate as float), 3)', $differs('l.rate', 's.rate', 'real'));
    }

    #[Test]
    public function a_retype_is_refused_on_a_driver_with_no_comparison_of_its_own(): void
    {
        $this->declare(CodedArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('article')->insert(['site_id' => 1, 'code' => '42']);

        $this->declare(RecodedArticle::class);
        $diff = app(ContentSchema::class)->diff();

        /* The name only. Everything lossy() runs before the refusal is the
           connection's own, and the refusal comes before the first statement
           written for the driver it does not know. */
        $driver = DB::getDriverName();
        (fn () => $this->config['driver'] = 'firebird')->call(DB::connection());

        /* Watched rather than looked for afterwards: the scratch table is
           dropped in a finally either way, so only the statements themselves
           show whether the refusal came first. */
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        try {
            app(ContentSchema::class)->lossy($diff);
            $this->fail('A driver with no comparison of its own was asked for one.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('firebird', $e->getMessage());
        } finally {
            (fn () => $this->config['driver'] = $driver)->call(DB::connection());
        }

        $this->assertEmpty(array_filter($statements, fn (string $sql) => str_contains($sql, 'mainstay_scratch_')), 'Refused before anything was built for it.');
    }

    #[Test]
    public function sync_asks_before_narrowing_a_string_that_holds_longer_values(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        Schema::table('article', fn ($table) => $table->string('title', 255)->change());
        $this->insertArticle(['title' => str_repeat('a', 200)]);

        /* SQLite reports no length, so a narrower string is no difference to
           it: nothing to ask about, and nothing cut. Postgres's cast cuts the
           string short; strict MySQL refuses to. */
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame([], app(ContentSchema::class)->diff());
            $this->artisan('mainstay:sync')->assertSuccessful();
        } elseif (DB::getDriverName() === 'pgsql') {
            $this->artisan('mainstay:sync')
                ->expectsConfirmation('Retype article.title, and change values stored in them that the new type cannot hold?', 'no')
                ->assertFailed();
        } else {
            try {
                Artisan::call('mainstay:sync');
                $this->fail('Strict MySQL took a string longer than its column.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('article holds values the declared type of article.title refuses', $e->getMessage());
            }
        }

        $this->assertSame(200, strlen(DB::table('article')->value('title')));
    }

    #[Test]
    public function only_a_type_change_that_changes_stored_numbers_is_lossy(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle(['reading_minutes' => 42]);

        $this->declare(RevisedArticle::class);
        $this->assertNotContains('article.reading_minutes', app(ContentSchema::class)->lossy(app(ContentSchema::class)->diff()), 'An integer widened to a float keeps its value.');

        $this->artisan('mainstay:sync --force')->assertSuccessful();
        DB::table('article')->update(['reading_minutes' => 1.5]);

        /* SQLite keeps 1.5 in an integer column; the servers round it. */
        $this->declare(Article::class);
        $lossy = app(ContentSchema::class)->lossy(app(ContentSchema::class)->diff());

        DB::getDriverName() === 'sqlite'
            ? $this->assertNotContains('article.reading_minutes', $lossy)
            : $this->assertContains('article.reading_minutes', $lossy);
    }

    #[Test]
    public function sync_refuses_a_value_the_new_type_cannot_hold_before_marking_even_when_forced(): void
    {
        $this->declare(CodedArticle::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('migrations')->where('migration', ContentSchema::MARKER)->delete();
        DB::table('article')->insert(['site_id' => 1, 'code' => 'abc']);

        $this->declare(RecodedArticle::class);

        /* SQLite stores the text in an integer column as it is, so the new
           type can hold it: nothing is refused, because nothing is lost, and
           the database is marked as one sync has altered. */
        if (DB::getDriverName() === 'sqlite') {
            $this->artisan('mainstay:sync --force')->assertSuccessful();

            $this->assertSame('abc', DB::table('article')->value('code'));
            $this->assertSame([], app(ContentSchema::class)->diff());
            $this->assertTrue(DB::table('migrations')->where('migration', ContentSchema::MARKER)->exists());
        } else {
            try {
                Artisan::call('mainstay:sync', ['--force' => true]);
                $this->fail('An integer column took abc.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('article holds values the declared type of article.code refuses', $e->getMessage());
            }

            $this->assertSame('abc', DB::table('article')->value('code'));
            $this->assertFalse(DB::table('migrations')->where('migration', ContentSchema::MARKER)->exists());
        }
    }

    #[Test]
    public function sync_leaves_the_marker_when_it_fails_after_altering_a_table(): void
    {
        config()->set('mainstay.locales', ['nl', 'en']);
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('migrations')->where('migration', ContentSchema::MARKER)->delete();
        $id = $this->insertArticle();
        DB::table('article_locales')->insert([
            ['parent_id' => $id, 'site_id' => 1, 'locale' => 'fr', 'summary' => 'Kept'],
            ['parent_id' => $id, 'site_id' => 1, 'locale' => 'de', 'summary' => 'Kept'],
        ]);
        Schema::table('article_locales', fn ($table) => $table->dropForeign(['parent_id']));
        Schema::table('article_locales', fn ($table) => $table->dropUnique(['parent_id', 'locale']));
        Schema::table('article_locales', fn ($table) => $table->dropColumn('locale'));

        /* article gains columns; article_locales, second, gets both rows the
           default locale and its unique index back, which refuses them. */
        $this->declare(RevisedArticle::class);

        /* QueryException rather than UniqueConstraintViolationException:
           Laravel reads that from the driver's error for a row refused on
           insert, and SQL Server refuses this one while building the index
           instead, which is an error of its own. What the assertions below
           want is that sync stopped, whatever it was told. */
        try {
            Artisan::call('mainstay:sync', ['--force' => true]);
            $this->fail('The restored unique index took two rows with the same parent and locale.');
        } catch (QueryException) {
        }

        $this->assertTrue(Schema::hasColumn('article', 'headline'), 'The table ahead of the failure was altered.');
        $this->assertSame(1, DB::table('migrations')->where('migration', ContentSchema::MARKER)->count());
    }

    #[Test]
    public function sync_gives_a_missing_locale_the_default_and_restores_the_locale_keys(): void
    {
        config()->set('mainstay.locales', ['nl', 'en']);
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $id = $this->insertArticle();
        DB::table('article_locales')->insert(['parent_id' => $id, 'site_id' => 1, 'locale' => 'fr', 'summary' => 'Kept']);
        Schema::table('article_locales', fn ($table) => $table->dropForeign(['parent_id']));
        Schema::table('article_locales', fn ($table) => $table->dropUnique(['parent_id', 'locale']));
        Schema::table('article_locales', fn ($table) => $table->dropColumn('locale'));

        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $this->assertSame('nl', DB::table('article_locales')->value('locale'));
        $this->assertContains(['parent_id', 'locale'], collect(Schema::getIndexes('article_locales'))->where('unique', true)->pluck('columns')->all());
        $this->assertSame([], app(ContentSchema::class)->diff());
    }

    #[Test]
    public function sync_restores_the_parent_key_with_the_column(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        Schema::table('article_locales', fn ($table) => $table->dropForeign(['parent_id']));
        Schema::table('article_locales', fn ($table) => $table->dropUnique(['parent_id', 'locale']));
        Schema::table('article_locales', fn ($table) => $table->dropColumn('parent_id'));

        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $keys = collect(Schema::getForeignKeys('article_locales'))->mapWithKeys(fn (array $key) => [$key['columns'][0] => $key['foreign_table']]);

        $this->assertSame('article', $keys['parent_id']);
        $this->assertContains(['parent_id', 'locale'], collect(Schema::getIndexes('article_locales'))->where('unique', true)->pluck('columns')->all());
    }

    #[Test]
    public function sync_refuses_to_fill_a_site_when_there_is_none(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->insertArticle();
        Schema::table('article', fn ($table) => $table->dropForeign(['site_id']));
        Schema::table('article', fn ($table) => $table->dropColumn('site_id'));
        DB::table('sites')->delete();

        try {
            Artisan::call('mainstay:sync', ['--force' => true]);
            $this->fail('There is no site to give the row.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('article.site_id is required, and there is no site', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('article', 'site_id'));
    }

    #[Test]
    public function sync_refuses_before_the_sites_table_exists(): void
    {
        $this->declare(Article::class);
        Schema::drop('uris');
        Schema::drop('sites');

        $this->assertSame(1, Artisan::call('mainstay:sync'));
        $this->assertStringContainsString('Run php artisan migrate first', Artisan::output());
        $this->assertFalse(Schema::hasTable('article'));
    }

    /*
     | The other half of leaving the primary key alone: a table that has none
     | at all. Added the way every other column is -- nullable, then filled --
     | it is refused outright, and a key cannot be added to a table that
     | exists anyway. So it is reported and left, like a key of the wrong type.
     */
    #[Test]
    public function sync_leaves_a_table_that_has_no_primary_key_alone(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        Schema::drop('article_locales');
        Schema::create('article_locales', function ($table) {
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('site_id');
            $table->string('locale');
            $table->text('summary')->nullable();
            $table->softDeletes();
            $table->foreign('parent_id')->references('id')->on('article');
            $table->foreign('site_id')->references('id')->on('sites');
            $table->unique(['parent_id', 'locale']);
        });
        DB::table('migrations')->where('migration', ContentSchema::MARKER)->delete();

        $this->assertSame(1, Artisan::call('mainstay:sync', ['--force' => true]));

        $output = Artisan::output();

        $this->assertStringContainsString('article_locales.id: declared, and the column does not exist', $output);
        $this->assertStringContainsString('Sync could not close every difference', $output);
        $this->assertNotContains('id', Schema::getColumnListing('article_locales'));
        $this->assertFalse(DB::table('migrations')->where('migration', ContentSchema::MARKER)->exists(), 'Nothing was altered, so there is nothing for migrate to warn about.');
    }

    #[Test]
    public function sync_reports_a_difference_it_leaves_alone_and_fails(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        /*
         | The sibling rather than the main table: nothing references its
         | primary key, so the key can be made to differ without dropping a
         | foreign key -- which would be a second difference, and which MySQL
         | would refuse to put back pointing a bigint at a varchar.
         */
        Schema::drop('article_locales');
        Schema::create('article_locales', function ($table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('parent_id');
            $table->unsignedBigInteger('site_id');
            $table->string('locale');
            $table->text('summary')->nullable();
            $table->softDeletes();
            $table->foreign('parent_id')->references('id')->on('article');
            $table->foreign('site_id')->references('id')->on('sites');
            $table->unique(['parent_id', 'locale']);
        });
        DB::table('migrations')->where('migration', ContentSchema::MARKER)->delete();

        $this->assertSame(1, Artisan::call('mainstay:sync', ['--force' => true]));

        $output = Artisan::output();

        $this->assertStringContainsString('Sync could not close every difference', $output);
        $this->assertStringContainsString('article_locales.id:', $output);
        $this->assertStringNotContainsString('The database matches', $output);
        $this->assertFalse(DB::table('migrations')->where('migration', ContentSchema::MARKER)->exists(), 'The key was left alone, so nothing was altered for migrate to warn about.');
    }

    #[Test]
    public function a_scratch_table_is_dropped_when_reading_it_back_fails(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();

        /* Fails after the scratch table exists, which is the case the drop has
           to survive: a listener runs once its statement has executed. */
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'create table') && str_contains($query->sql, 'mainstay_scratch_')) {
                throw new RuntimeException('Reading the scratch table back failed.');
            }
        });

        try {
            app(ContentSchema::class)->diff();
            $this->fail('The failure after creating the scratch table did not surface.');
        } catch (RuntimeException $e) {
            $this->assertSame('Reading the scratch table back failed.', $e->getMessage());
        }

        $this->assertEmpty(array_filter(Schema::getTableListing(schemaQualified: false), fn (string $name) => str_starts_with($name, 'mainstay_scratch_')));
    }

    #[Test]
    public function sync_settles_every_table_before_altering_any(): void
    {
        $this->declare(Article::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        DB::table('migrations')->where('migration', ContentSchema::MARKER)->delete();
        $id = $this->insertArticle();
        DB::table('article_locales')->insert(['parent_id' => $id, 'site_id' => 1, 'locale' => 'en', 'summary' => 'Kept']);
        Schema::table('article_locales', fn ($table) => $table->dropForeign(['parent_id']));
        Schema::table('article_locales', fn ($table) => $table->dropUnique(['parent_id', 'locale']));
        Schema::table('article_locales', fn ($table) => $table->dropColumn('parent_id'));

        /* article gains a column sync can fill; article_locales regains one it
           cannot, and comes second. */
        $this->declare(RevisedArticle::class);

        try {
            Artisan::call('mainstay:sync', ['--force' => true]);
            $this->fail('A locale row with no parent has none to be given.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('article_locales.parent_id is required', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('article', 'headline'));
        $this->assertTrue(Schema::hasColumn('article', 'title'));
        $this->assertFalse(DB::table('migrations')->where('migration', ContentSchema::MARKER)->exists(), 'Nothing was altered, so migrate has nothing to warn about.');
    }

    #[Test]
    public function a_type_that_would_collide_with_a_column_or_table_is_refused(): void
    {
        foreach ([
            Reserved::class => 'has a field stored as site_id, a column Mainstay keeps for itself',
            Collided::class => 'has two properties stored in the same column',
            Uris::class => 'would be stored in uris, a table Mainstay keeps for itself',
        ] as $type => $message) {
            $this->declare($type);

            try {
                app(ContentSchema::class)->diff();
                $this->fail("{$type} was not refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    #[Test]
    public function sync_refuses_in_production_and_when_the_flag_is_off(): void
    {
        $this->declare(Article::class);

        config()->set('mainstay.schema.sync', false);
        $this->artisan('mainstay:sync --force')->assertFailed();

        config()->set('mainstay.schema.sync', true);
        $this->app['env'] = 'production';
        $this->artisan('mainstay:sync --force')->assertFailed();
        $this->assertFalse(Schema::hasTable('article'));
    }

    #[Test]
    public function migrate_warns_on_a_database_sync_has_altered(): void
    {
        $this->declare(Article::class);

        $output = fn () => tap(new BufferedOutput, fn ($output) => Event::dispatch(new CommandStarting('migrate', new ArrayInput([]), $output)))->fetch();

        $this->assertSame('', $output());

        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->assertStringContainsString('mainstay:sync has altered this database', $output());

        $this->declare(RevisedArticle::class);
        $this->artisan('mainstay:sync --force')->assertSuccessful();

        $this->assertSame(1, DB::table('migrations')->where('migration', ContentSchema::MARKER)->count(), 'The marker is written once.');
        $this->assertSame(-1, (int) DB::table('migrations')->where('migration', ContentSchema::MARKER)->value('batch'));
    }
}
