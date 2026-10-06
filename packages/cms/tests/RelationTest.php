<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Database\ContentSchema;
use Mainstay\Facades\Mainstay;
use Mainstay\Fields\Relation;
use Mainstay\Fields\Terms;
use Mainstay\Mainstay as Registry;
use Mainstay\Tests\Fixtures\Linked\Broken\Demanding;
use Mainstay\Tests\Fixtures\Linked\Broken\Filed;
use Mainstay\Tests\Fixtures\Linked\Broken\Labelled;
use Mainstay\Tests\Fixtures\Linked\Broken\Lost;
use Mainstay\Tests\Fixtures\Linked\Broken\Narrow;
use Mainstay\Tests\Fixtures\Linked\Broken\StoryEntries;
use Mainstay\Tests\Fixtures\Linked\Broken\Twice;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Masthead;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Review;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Policies\ClosedPolicy;
use Mainstay\Tests\Fixtures\Policies\EditorPolicy;
use Mainstay\Tests\Fixtures\Policies\OpenPolicy;
use Mainstay\Tests\Fixtures\SiteSettings;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 7 check: relations in every shape, followed to the depth a read
 | asks for, with what a read did not load kept in its place; globals, one
 | row per site; and terms, written to their taxonomy's pivot and found again
 | by the reverse query a term's page runs.
 */
class RelationTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
        $app['view']->addLocation(__DIR__.'/Fixtures/views');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Person::class, Story::class, Genre::class, Review::class, Masthead::class, SiteSettings::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    /* An entry in English, and in Dutch unless `$dutch` is false, with
       `(nl)` after its Dutch title. */
    private function write(string $type, string $title, array $data = [], bool $dutch = true): object
    {
        $slug = Str::slug($title);
        $entry = Mainstay::create($type, ['title' => $title, 'slug' => $slug, ...$data], locale: 'en', overrideAccess: true);

        if ($dutch) {
            Mainstay::update($type, $entry->id, ['title' => "{$title} (nl)", 'slug' => $slug], locale: 'nl', overrideAccess: true);
        }

        return $entry;
    }

    private function person(string $title, array $data = [], bool $dutch = true): Person
    {
        return $this->write(Person::class, $title, $data, $dutch);
    }

    private function story(string $title, array $data = [], bool $dutch = true): Story
    {
        return $this->write(Story::class, $title, $data, $dutch);
    }

    private function genre(string $title): Genre
    {
        return $this->write(Genre::class, $title);
    }

    /* A shelf of one spine per story given. */
    private function shelf(mixed ...$stories): array
    {
        return [['type' => 'shelf', 'data' => ['spines' => array_map(fn (mixed $story) => ['type' => 'spine', 'data' => ['story' => $story]], $stories)]]];
    }

    /* An image in the library, put there as its row alone: a read of one
       needs nothing more. */
    private function image(): int
    {
        return DB::table('mainstay_media')->insertGetId([
            'hash' => hash('sha256', Str::random()), 'type' => 'image/png', 'name' => 'face.png', 'bytes' => 1, 'width' => 64, 'height' => 64,
            'focal_x' => 50, 'focal_y' => 50, 'alt' => json_encode(['en' => 'A face', 'nl' => 'Een gezicht']),
            'created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00',
        ]);
    }

    /* The field errors a write was refused with. */
    private function refusal(callable $write): array
    {
        try {
            $write();
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        $this->fail('The write was not refused.');
    }

    /* A write from a second connection, true once it lands. A caller's
       transaction on SQLite holds the file, so there the write is refused,
       which is what that driver does instead. */
    private function beside(callable $write): bool
    {
        if (DB::getDriverName() !== 'sqlite' || DB::transactionLevel() === 0) {
            $write();

            return true;
        }

        $this->assertThrows($write, QueryException::class, 'database is locked');

        return false;
    }

    /* `$table` without its unique index `$index`, its foreign key on
       `$column` taken off and put back around the drop. */
    private function withoutUnique(string $table, string $index, string $column, string $on): void
    {
        Schema::table($table, fn ($blueprint) => $blueprint->dropForeign([$column]));
        Schema::table($table, fn ($blueprint) => $blueprint->dropUnique($index));
        Schema::table($table, fn ($blueprint) => $blueprint->foreign($column)->references('id')->on($on));
    }

    #[Test]
    public function a_relation_holds_the_same_class_at_every_depth(): void
    {
        $ann = $this->person('Ann');
        $bob = $this->person('Bob');
        $first = $this->story('First', ['author' => $bob]);
        $story = $this->story('Second', [
            'author' => $ann,
            'related' => [$first->id],
            'pick' => $first,
            'picks' => [$bob, ['type' => 'story', 'id' => $first->id]],
            'blocks' => $this->shelf($first->id),
        ]);

        $row = DB::table('story')->find($story->id);
        $this->assertSame($ann->id, (int) $row->author, 'One of one type is a plain id in a column.');
        $this->assertSame([$first->id], json_decode($row->related, true), 'A list of one type is a list of ids.');
        /* Equal rather than identical: MySQL keeps a JSON object's keys in an
           order of its own. */
        $this->assertEquals(['type' => 'story', 'id' => $first->id], json_decode($row->pick, true));
        $this->assertEquals([['type' => 'person', 'id' => $bob->id], ['type' => 'story', 'id' => $first->id]], json_decode($row->picks, true));
        $this->assertSame($first->id, json_decode($row->blocks, true)[0]['data']['spines'][0]['data']['story']);

        $flat = Mainstay::findById(Story::class, $story->id, locale: 'en', depth: 0);
        $loaded = Mainstay::findById(Story::class, $story->id, locale: 'en');
        $deep = Mainstay::findById(Story::class, $story->id, locale: 'en', depth: 2);

        foreach ([$flat, $loaded, $deep] as $read) {
            $this->assertInstanceOf(Person::class, $read->author);
            $this->assertSame($ann->id, $read->author->id);
            $this->assertInstanceOf(Story::class, $read->related[0]);
            $this->assertInstanceOf(Story::class, $read->pick);
            $this->assertInstanceOf(Person::class, $read->picks[0]);
            $this->assertInstanceOf(Story::class, $read->picks[1]);
            $this->assertInstanceOf(Story::class, $read->blocks[0]->spines[0]->story);
        }

        $this->assertTrue($flat->author->missing);
        $this->assertFalse(isset($flat->author->title), 'Nothing but the id.');
        $this->assertFalse(isset($flat->author->listed), 'Not the default its declaration gives either.');
        $this->assertNull($flat->author->url());

        $this->assertFalse($loaded->author->missing);
        $this->assertSame('Ann', $loaded->author->title);
        $this->assertSame('/people/ann', $loaded->author->uri);
        $this->assertSame(['First', 'First', 'Bob', 'First'], [$loaded->related[0]->title, $loaded->pick->title, $loaded->picks[0]->title, $loaded->blocks[0]->spines[0]->story->title]);
        $this->assertTrue($loaded->related[0]->author->missing, 'Its own relations wait for depth 2.');

        $this->assertSame('Bob', $deep->related[0]->author->title);
        $this->assertSame('Ann (nl)', Mainstay::findById(Story::class, $story->id, locale: 'nl')->author->title, 'In the locale read.');
        $this->assertSame('Ann', Mainstay::update(Story::class, $story->id, ['title' => 'Second, again'], locale: 'en', overrideAccess: true)->author->title, 'A write hands back what a read at the default depth would.');

        $this->assertThrows(fn () => Mainstay::find(Story::class, depth: -1), InvalidArgumentException::class, 'A read follows its relations to a depth of 0 or more; -1 is not one.');
    }

    #[Test]
    public function a_level_costs_one_query_per_type_however_many_rows(): void
    {
        $ann = $this->person('Ann', ['portrait' => $this->image()]);
        $noir = $this->genre('Noir');
        $first = $this->story('First', ['author' => $ann, 'genres' => [$noir->id]]);

        $queries = function (int $depth): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $stories = Mainstay::find(Story::class, locale: 'en', depth: $depth);
            DB::disableQueryLog();

            if ($depth > 0) {
                $this->assertFalse($stories->first()->author->portrait->missing, 'An entry loaded at a level has its images.');
                $this->assertSame('Noir', $stories->first()->genres[0]->title);
            }

            return count(DB::getQueryLog());
        };

        foreach (['Second', 'Third'] as $title) {
            $this->story($title, ['author' => $ann, 'related' => [$first->id], 'genres' => [$noir->id]]);
        }

        $two = [$queries(0), $queries(1)];

        foreach (['Fourth', 'Fifth', 'Sixth', 'Seventh'] as $title) {
            $this->story($title, ['author' => $ann, 'related' => [$first->id], 'genres' => [$noir->id]]);
        }

        $this->assertSame($two, [$queries(0), $queries(1)], 'As many queries for seven stories as for three.');

        /* The site, the stories and their genres' pivot; then a level: the
           people, the genres and the stories they point at, those stories'
           pivot, and the people's portraits. */
        $this->assertSame([3, 8], $two);
    }

    #[Test]
    public function a_targets_internal_fields_answer_to_its_own_policy(): void
    {
        $story = $this->story('Plain', ['author' => $this->person('Ann', ['note' => 'Private'])]);

        $this->assertFalse(isset(Mainstay::findById(Story::class, $story->id, locale: 'en')->author->note), "Hidden, by a person's policy.");

        Gate::policy(Person::class, OpenPolicy::class);

        $this->assertSame('Private', Mainstay::findById(Story::class, $story->id, locale: 'en')->author->note, 'Shown, by its own policy, beside a story whose policy shows nothing internal.');
    }

    #[Test]
    public function a_target_that_is_away_reads_as_missing_and_keeps_its_place(): void
    {
        $gone = $this->person('Gone');
        $english = $this->story('English only', dutch: false);
        $kept = $this->story('Kept');
        $story = $this->story('Holder', [
            'author' => $gone,
            'related' => [$english->id, $kept->id],
            'picks' => [$gone, $kept],
            'blocks' => $this->shelf($english->id),
        ]);
        Mainstay::delete(Person::class, $gone->id, overrideAccess: true);

        $read = Mainstay::findById(Story::class, $story->id, locale: 'nl');
        $this->assertTrue($read->author->missing, 'Trashed.');
        $this->assertSame($gone->id, $read->author->id);
        $this->assertSame([[true, $english->id], [false, $kept->id]], array_map(fn (Story $one) => [$one->missing, $one->id], $read->related), 'Untranslated, and in its place.');
        $this->assertSame([true, false], [$read->picks[0]->missing, $read->picks[1]->missing]);
        $this->assertTrue($read->blocks[0]->spines[0]->story->missing);

        /* Saved beside another field, and given back as read. */
        Mainstay::update(Story::class, $story->id, ['title' => 'Holder, again'], locale: 'nl', overrideAccess: true);
        Mainstay::update(Story::class, $story->id, ['author' => $read->author, 'related' => $read->related, 'picks' => $read->picks, 'blocks' => $read->blocks], locale: 'nl', overrideAccess: true);

        $row = DB::table('story')->find($story->id);
        $this->assertSame($gone->id, (int) $row->author);
        $this->assertSame([$english->id, $kept->id], json_decode($row->related, true));
        $this->assertEquals([['type' => 'person', 'id' => $gone->id], ['type' => 'story', 'id' => $kept->id]], json_decode($row->picks, true));
        $this->assertSame($english->id, json_decode($row->blocks, true)[0]['data']['spines'][0]['data']['story']);
        $this->artisan('mainstay:schema:check')->assertSuccessful();

        /* Moved to another field, it is a new choice, and refused. */
        $this->assertArrayHasKey('pick', $this->refusal(fn () => Mainstay::update(Story::class, $story->id, ['pick' => $read->author], locale: 'nl', overrideAccess: true)));

        /* Another site's, and one of a type the reader may not read, in a
           column, a list and a block, and kept through a save of them as
           read. */
        $site = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);
        $elsewhere = DB::table('story')->insertGetId(['site_id' => $site, 'related' => '[]', 'picks' => '[]', 'blocks' => '[]', 'created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00']);
        DB::table('story_locales')->insert(['parent_id' => $elsewhere, 'site_id' => $site, 'locale' => 'en', 'title' => 'Elsewhere', 'slug' => 'elsewhere']);
        $ann = $this->person('Ann');
        $away = $this->story('Away', ['author' => $ann, 'related' => [$kept->id], 'picks' => [$ann, $kept], 'blocks' => $this->shelf($kept->id)]);
        DB::table('story')->where('id', $away->id)->update([
            'related' => json_encode([$elsewhere, $kept->id]),
            'blocks' => json_encode([['id' => 'a', 'type' => 'shelf', 'data' => ['spines' => [['id' => 'b', 'type' => 'spine', 'data' => ['story' => $elsewhere]]]]]]),
        ]);
        $stored = DB::table('story')->find($away->id);

        Gate::policy(Story::class, EditorPolicy::class);
        Gate::policy(Person::class, ClosedPolicy::class);
        $read = Mainstay::findById(Story::class, $away->id, locale: 'en');
        $this->assertTrue($read->author->missing, 'Of a type the reader may not read.');
        $this->assertSame([[true, $elsewhere], [false, $kept->id]], array_map(fn (Story $one) => [$one->missing, $one->id], $read->related), 'On another site, in its place.');
        $this->assertSame([true, false], [$read->picks[0]->missing, $read->picks[1]->missing]);
        $this->assertTrue($read->blocks[0]->spines[0]->story->missing);

        Mainstay::update(Story::class, $away->id, ['author' => $read->author, 'related' => $read->related, 'picks' => $read->picks, 'blocks' => $read->blocks], locale: 'en');
        $after = DB::table('story')->find($away->id);
        $this->assertSame([(int) $stored->author, json_decode($stored->related, true), json_decode($stored->blocks, true)[0]['data']['spines'][0]['data']['story']], [(int) $after->author, json_decode($after->related, true), json_decode($after->blocks, true)[0]['data']['spines'][0]['data']['story']]);
        $this->assertEquals(json_decode($stored->picks, true), json_decode($after->picks, true));
    }

    #[Test]
    public function a_write_naming_what_is_not_there_is_refused_where_it_is(): void
    {
        $ann = $this->person('Ann');
        $gone = $this->person('Gone');
        Mainstay::delete(Person::class, $gone->id, overrideAccess: true);
        $english = $this->story('English only', dutch: false);
        $story = $this->story('Holder', ['related' => [$english->id]]);
        $before = DB::table('story')->find($story->id);
        $write = fn (array $data) => Mainstay::update(Story::class, $story->id, $data, locale: 'en', overrideAccess: true);

        $this->assertSame(['author' => ['The author field names person 999, which is not there to point at.']], $this->refusal(fn () => $write(['author' => 999])));
        $this->assertSame(['author' => ["The author field names person {$gone->id}, which is not there to point at."]], $this->refusal(fn () => $write(['author' => $gone->id])));
        $this->assertSame(['picks.1' => ['The picks.1 field names story 999, which is not there to point at.']], $this->refusal(fn () => $write(['picks' => [$ann, ['type' => 'story', 'id' => 999]]])));
        $this->assertSame(['blocks.0.data.spines.0.data.story' => ['The blocks.0.data.spines.0.data.story field names story 999, which is not there to point at.']], $this->refusal(fn () => $write(['blocks' => $this->shelf(999)])));
        $this->assertSame(['author' => ['The author field points at person, not story.']], $this->refusal(fn () => $write(['author' => $english])));
        $this->assertArrayHasKey('picks.0.type', $this->refusal(fn () => $write(['picks' => [['type' => 'genre', 'id' => 1]]])));
        $this->assertSame(['related.1' => ['The related.1 field names an entry already in the list.']], $this->refusal(fn () => $write(['related' => [$english->id, $english]])));
        $this->assertSame(['picks.1' => ['The picks.1 field names an entry already in the list.']], $this->refusal(fn () => $write(['picks' => [$ann, ['type' => 'person', 'id' => $ann->id]]])));
        $this->assertEquals($before, DB::table('story')->find($story->id), 'Nothing written.');

        /* Another site's is not there either. */
        $site = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);
        $elsewhere = DB::table('person')->insertGetId(['site_id' => $site, 'listed' => true, 'created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00']);
        $this->assertSame(['author' => ["The author field names person {$elsewhere}, which is not there to point at."]], $this->refusal(fn () => $write(['author' => $elsewhere])));

        /* Not translated into the write's locale: written, and missing there. */
        $this->assertTrue(Mainstay::update(Story::class, $story->id, ['pick' => $english], locale: 'nl', overrideAccess: true)->pick->missing);

        /* To a writer who may not read people, Ann is not there either. */
        Gate::policy(Story::class, EditorPolicy::class);
        Gate::policy(Person::class, ClosedPolicy::class);
        $this->assertSame(['author' => ["The author field names person {$ann->id}, which is not there to point at."]], $this->refusal(fn () => Mainstay::update(Story::class, $story->id, ['author' => $ann->id], locale: 'en')));
    }

    #[Test]
    public function a_reference_to_a_type_the_field_no_longer_names_is_left_out(): void
    {
        $ann = $this->person('Ann');
        $story = $this->story('Holder');
        DB::table('story')->where('id', $story->id)->update([
            'pick' => json_encode(['type' => 'genre', 'id' => 1]),
            'picks' => json_encode([['type' => 'genre', 'id' => 1], ['type' => 'person', 'id' => $ann->id]]),
        ]);

        $read = Mainstay::findById(Story::class, $story->id, locale: 'en');
        $this->assertNull($read->pick);
        $this->assertSame([$ann->id], array_map(fn (Person $person) => $person->id, $read->picks));

        /* Another field saved past it, and the list given back without it. */
        Mainstay::update(Story::class, $story->id, ['title' => 'Holder, again'], locale: 'en', overrideAccess: true);
        Mainstay::update(Story::class, $story->id, ['picks' => $read->picks], locale: 'en', overrideAccess: true);
        $this->assertEquals([['type' => 'person', 'id' => $ann->id]], json_decode(DB::table('story')->where('id', $story->id)->value('picks'), true));
    }

    #[Test]
    public function a_single_relation_is_filtered_on_and_a_list_is_not(): void
    {
        $ann = $this->person('Ann');
        $bob = $this->person('Bob');
        $this->story('By Ann', ['author' => $ann]);
        $this->story('By Bob', ['author' => $bob]);
        $this->story('By nobody');

        $titles = fn (array $where) => Mainstay::find(Story::class, where: $where, sort: 'title', locale: 'en', depth: 0)->pluck('title')->all();

        $this->assertSame(['By Ann'], $titles(['author' => $ann]));
        $this->assertSame(['By Ann', 'By Bob'], $titles(['author' => ['in' => [$ann->id, $bob]]]));
        $this->assertSame(['By nobody'], $titles(['author' => null]));
        $this->assertSame(['By nobody'], $titles(['author' => '']), 'Blank, as a field reads blank.');
        $this->assertSame(['By Ann'], $titles(['author' => (string) $ann->id]), 'An id as a query string carries it.');

        /* An entry of another type, or what is no id, names no person. */
        foreach ([$this->genre('Noir'), Mainstay::find(Story::class, limit: 1)->first(), 'abc', '0', 1.5] as $wrong) {
            $this->assertThrows(fn () => $titles(['author' => $wrong]), InvalidArgumentException::class, "The condition on author names something that is not a person or a person's id.");
            $this->assertThrows(fn () => $titles(['author' => ['in' => [$ann->id, $wrong]]]), InvalidArgumentException::class, "The condition on author names something that is not a person or a person's id.");
        }
        $this->assertThrows(fn () => $titles(['related' => $ann->id]), InvalidArgumentException::class, 'Story::$related is kept as JSON, which is not filtered or sorted on.');
    }

    #[Test]
    public function a_global_is_absent_until_written_and_in_a_locale_it_lacks(): void
    {
        $first = $this->story('First');
        $second = $this->story('Second');

        $this->assertNull(Mainstay::global(Masthead::class, locale: 'en'));

        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Read on', 'menu' => [$first, $second], 'secret' => 'Hidden'], locale: 'en', overrideAccess: true);
        $this->assertNull(Mainstay::global(Masthead::class, locale: 'nl'), 'Not written in Dutch.');

        App::setLocale('nl');
        $this->assertThrows(fn () => Mainstay::saveGlobal(Masthead::class, ['motto' => 'Lees verder'], overrideAccess: true), RecordNotFoundException::class, "has no nl translation to save. Pass locale: 'nl' to add one.");
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Lees verder', 'menu' => [$second]], locale: 'nl', overrideAccess: true);
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Lees maar verder'], overrideAccess: true);
        App::setLocale('en');

        $english = Mainstay::global(Masthead::class);
        $dutch = Mainstay::global(Masthead::class, locale: 'nl');
        $this->assertSame(['Read on', ['First', 'Second']], [$english->motto, array_map(fn (Story $story) => $story->title, $english->menu)]);
        $this->assertSame(['Lees maar verder', ['Second (nl)']], [$dutch->motto, array_map(fn (Story $story) => $story->title, $dutch->menu)], 'A menu of its own.');
        $this->assertTrue(Mainstay::global(Masthead::class, depth: 0)->menu[0]->missing);
        $this->assertFalse(isset($english->secret), 'Internal.');
        $this->assertSame('Hidden', Mainstay::global(Masthead::class, overrideAccess: true)->secret);
        $this->assertSame(1, DB::table('masthead')->count(), 'One row for the site.');

        $this->assertThrows(fn () => Mainstay::find(Masthead::class), InvalidArgumentException::class, 'Masthead is a global. Read it with Mainstay::global()');
        $this->assertThrows(fn () => Mainstay::global(Story::class), InvalidArgumentException::class, 'Story is not a global.');
    }

    #[Test]
    public function a_global_is_written_only_with_the_override_until_there_is_a_user(): void
    {
        $this->assertThrows(fn () => Mainstay::saveGlobal(Masthead::class, ['motto' => 'Read on'], locale: 'en'), AuthorizationException::class, 'Writing a global needs a Mainstay user');
        $this->assertSame(0, DB::table('masthead')->count());

        /* Its read leaves out the view and the path an entry has, so a
           field called template is the global's own. */
        Mainstay::saveGlobal(SiteSettings::class, ['siteName' => 'Mainstay', 'template' => 'any words'], locale: 'en', overrideAccess: true);
        $this->assertSame(['Mainstay', 'any words'], [Mainstay::global(SiteSettings::class)->siteName, Mainstay::global(SiteSettings::class)->template]);
    }

    #[Test]
    public function two_first_saves_of_a_global_leave_one_row(): void
    {
        config()->set('database.connections.beside', config('database.connections.testing'));

        /* The moment the save has looked for the row and found none, another
           connection writes it. */
        $race = function () use (&$raced): void {
            $raced = null;

            DB::listen(function ($query) use (&$raced) {
                if ($raced === null && str_contains($query->sql, 'masthead') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                    $raced = false;
                    $raced = $this->beside(function () {
                        $id = DB::connection('beside')->table('masthead')->insertGetId(['site_id' => 1, 'created_at' => '2026-10-06 00:00:00', 'updated_at' => '2026-10-06 00:00:00']);
                        DB::connection('beside')->table('masthead_locales')->insert(['parent_id' => $id, 'site_id' => 1, 'locale' => 'en', 'motto' => 'Beside', 'menu' => '[]']);
                    });
                }
            });
        };

        $race();
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Mine'], locale: 'en', overrideAccess: true);
        $this->assertTrue($raced);
        $this->assertSame([1, 'Mine'], [DB::table('masthead')->count(), DB::table('masthead_locales')->value('motto')], 'The other save\'s row, updated.');

        DB::table('masthead_locales')->delete();
        DB::table('masthead')->delete();

        /* Inside a caller's transaction, whose snapshot was taken before the
           other connection committed. */
        DB::beginTransaction();
        DB::table('sites')->count();
        $race();

        try {
            Mainstay::saveGlobal(Masthead::class, ['motto' => 'Mine'], locale: 'en', overrideAccess: true);
            $this->assertSame(1, DB::table('sites')->count(), 'The caller\'s transaction goes on.');
        } finally {
            DB::commit();
        }

        $this->assertSame([1, 'Mine'], [DB::table('masthead')->count(), DB::table('masthead_locales')->value('motto')]);
    }

    #[Test]
    public function terms_are_written_in_the_order_given_and_only_what_changed(): void
    {
        [$noir, $comedy, $myth] = [$this->genre('Noir'), $this->genre('Comedy'), $this->genre('Myth')];
        $story = $this->story('Tagged', ['genres' => [$comedy, $noir->id]]);

        $this->assertSame(['Comedy', 'Noir'], array_map(fn (Genre $genre) => $genre->title, Mainstay::findById(Story::class, $story->id, locale: 'en')->genres));
        $this->assertSame([$comedy->id, $noir->id], array_map(fn (Genre $genre) => $genre->id, Mainstay::findById(Story::class, $story->id, locale: 'en', depth: 0)->genres));
        $this->assertSame(['Comedy (nl)', 'Noir (nl)'], array_map(fn (Genre $genre) => $genre->title, Mainstay::findById(Story::class, $story->id, locale: 'nl')->genres), 'The same terms in every language.');

        $comedyRow = DB::table('genre_entries')->where('term_id', $comedy->id)->value('id');
        Mainstay::update(Story::class, $story->id, ['genres' => [$comedy, $myth]], locale: 'en', overrideAccess: true);

        $this->assertSame([[$comedy->id, 0], [$myth->id, 1]], DB::table('genre_entries')->orderBy('position')->get()->map(fn (object $row) => [(int) $row->term_id, (int) $row->position])->all());
        $this->assertSame($comedyRow, DB::table('genre_entries')->where('term_id', $comedy->id)->value('id'), 'Not deleted and put back.');

        Mainstay::update(Story::class, $story->id, ['genres' => [$myth, $comedy]], locale: 'en', overrideAccess: true);
        $this->assertSame(['Myth', 'Comedy'], array_map(fn (Genre $genre) => $genre->title, Mainstay::findById(Story::class, $story->id, locale: 'en')->genres), 'Moved.');

        $this->assertSame(['genres.1' => ['The genres.1 field names an entry already in the list.']], $this->refusal(fn () => Mainstay::update(Story::class, $story->id, ['genres' => [$noir, $noir->id]], locale: 'en', overrideAccess: true)));
        $this->assertSame(['genres.0' => ['The genres.0 field names genre 999, which is not there to point at.']], $this->refusal(fn () => Mainstay::update(Story::class, $story->id, ['genres' => [999]], locale: 'en', overrideAccess: true)));
        $this->assertSame(2, DB::table('genre_entries')->count());

        /* A term trashed reads as missing in its place, and a save of another
           field leaves its row where it is. */
        Mainstay::delete(Genre::class, $myth->id, overrideAccess: true);
        $this->assertSame([true, false], array_map(fn (Genre $genre) => $genre->missing, Mainstay::findById(Story::class, $story->id, locale: 'en')->genres));
        Mainstay::update(Story::class, $story->id, ['title' => 'Tagged, again'], locale: 'en', overrideAccess: true);
        $this->assertSame(2, DB::table('genre_entries')->count());

        /* The key to the term is a real one. */
        $this->assertThrows(fn () => DB::table('genre')->where('id', $comedy->id)->delete(), QueryException::class);
    }

    #[Test]
    public function the_pivot_tells_two_types_holding_one_taxonomy_apart(): void
    {
        [$noir, $comedy] = [$this->genre('Noir'), $this->genre('Comedy')];
        $story = $this->story('Story', ['genres' => [$noir]]);
        $review = Mainstay::create(Review::class, ['title' => 'Review', 'genres' => [$comedy]], locale: 'en', overrideAccess: true);
        $this->assertSame($story->id, $review->id, 'Each type numbers its own rows.');

        $this->assertSame([$noir->id], array_map(fn (Genre $genre) => $genre->id, Mainstay::findById(Story::class, $story->id, locale: 'en', depth: 0)->genres));
        $this->assertSame([$comedy->id], array_map(fn (Genre $genre) => $genre->id, Mainstay::findById(Review::class, $review->id, locale: 'en', depth: 0)->genres));
        $this->assertSame([], Mainstay::find(Story::class, where: ['genres' => $comedy], locale: 'en')->all());
        $this->assertSame(['Review'], Mainstay::find(Review::class, where: ['genres' => $comedy], locale: 'en')->pluck('title')->all());

        Mainstay::update(Review::class, $review->id, ['genres' => []], locale: 'en', overrideAccess: true);
        $this->assertSame([$noir->id], array_map(fn (Genre $genre) => $genre->id, Mainstay::findById(Story::class, $story->id, locale: 'en', depth: 0)->genres), "The review's terms went, and the story's stayed.");
    }

    #[Test]
    public function the_reverse_query_pages_and_sorts_like_any_filter(): void
    {
        [$noir, $comedy] = [$this->genre('Noir'), $this->genre('Comedy')];

        foreach (['Alpha' => [$noir], 'Bravo' => [$noir, $comedy], 'Charlie' => [$comedy], 'Delta' => [$noir], 'Echo' => []] as $title => $genres) {
            $this->story($title, ['genres' => $genres]);
        }

        $page = Mainstay::paginate(Story::class, where: ['genres' => $noir], sort: '-title', perPage: 2, page: 2, locale: 'en');
        $this->assertSame([3, 2, ['Alpha']], [$page->total(), $page->lastPage(), collect($page->items())->pluck('title')->all()]);
        $this->assertSame(4, Mainstay::paginate(Story::class, where: ['genres' => ['in' => [$noir->id, $comedy->id]]], perPage: 2, locale: 'en')->total(), 'Bravo holds both, and counts once.');

        $titles = fn (array $where) => Mainstay::find(Story::class, where: $where, sort: 'title', locale: 'en', depth: 0)->pluck('title')->all();
        $this->assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], $titles(['genres' => ['in' => [$noir->id, $comedy]]]));
        $this->assertSame(['Bravo'], $titles(['genres' => $noir->id, 'title' => ['>' => 'Alpha', '<' => 'Delta']]));

        $this->assertThrows(fn () => $titles(['genres' => ['!=' => $noir->id]]), InvalidArgumentException::class, 'genres holds terms, and a where finds the entries holding one with = or any of several with in.');
        $this->assertThrows(fn () => $titles(['genres' => 'noir']), InvalidArgumentException::class, "The condition on genres names something that is not a genre or a genre's id.");
        $this->assertThrows(fn () => $titles(['genres' => $this->story('Foxtrot')]), InvalidArgumentException::class, "The condition on genres names something that is not a genre or a genre's id.");
        $this->assertThrows(fn () => Mainstay::find(Story::class, sort: 'genres'), InvalidArgumentException::class, 'Story::$genres holds terms, which are not sorted on.');

        /* A trashed term gathers nothing, and its rows wait for it. */
        Mainstay::delete(Genre::class, $comedy->id, overrideAccess: true);
        $this->assertSame([], $titles(['genres' => $comedy->id]));
        $this->assertSame(2, DB::table('genre_entries')->where('term_id', $comedy->id)->count());
    }

    #[Test]
    public function a_term_has_its_page_in_every_locale(): void
    {
        $noir = $this->genre('Noir');
        $this->story('Alpha', ['genres' => [$noir]]);
        $this->story('Bravo');

        $this->get('/genres/noir')->assertOk()->assertSee('<h1>Noir</h1>', escape: false)->assertSee('Alpha')->assertDontSee('Bravo');
        $this->get('/nl/genres/noir')->assertOk()->assertSee('<h1>Noir (nl)</h1>', escape: false)->assertSee('Alpha (nl)');
    }

    #[Test]
    public function a_terms_field_is_refused_where_its_pivot_could_not_hold_it(): void
    {
        $registry = new Registry;

        $this->assertThrows(fn () => new Terms(localized: true, of: Genre::class), InvalidArgumentException::class, 'A terms field is not localized');
        $this->assertThrows(fn () => new Terms(of: Story::class), InvalidArgumentException::class, Story::class.' is not one.');
        $this->assertThrows(fn () => $registry->fields(Labelled::class), InvalidArgumentException::class, 'Labelled::$genres is a terms field, and only an entry holds terms');
        $this->assertThrows(fn () => $registry->fields(Filed::class), InvalidArgumentException::class, 'Filed::$genres is a terms field, and only an entry holds terms');
        $this->assertThrows(fn () => $registry->fields(Twice::class), InvalidArgumentException::class, 'Twice::$genres and $featured both hold');
    }

    #[Test]
    public function a_relation_is_declared_nullable_or_a_list_and_points_at_registered_entries(): void
    {
        $registry = new Registry;

        $this->assertThrows(fn () => $registry->fields(Demanding::class), InvalidArgumentException::class, 'Demanding::$author is not nullable, and a relation holds null for no entry chosen.');
        $this->assertThrows(fn () => $registry->fields(Narrow::class), InvalidArgumentException::class, 'Narrow::$pick is typed ?'.Person::class.', and a relation to one entry is typed as every type it points at: '.Person::class.'|'.Story::class.'.');
        $this->assertThrows(fn () => new Relation(to: Masthead::class), InvalidArgumentException::class, Masthead::class.' is not an entry, and a relation points at entries.');
        $this->assertThrows(fn () => new Relation(to: []), InvalidArgumentException::class, 'A relation names the entries it points at');

        $this->declare(Lost::class);
        $this->assertThrows(fn () => app(ContentSchema::class)->diff(), InvalidArgumentException::class, 'has a field stored as missing, a column Mainstay keeps for itself');
        $this->declare(StoryEntries::class);
        $this->assertThrows(fn () => app(ContentSchema::class)->diff(), InvalidArgumentException::class, 'would be stored in story_entries, a table Mainstay keeps for itself');

        $this->assertSame(['type' => ['integer', 'null']], $registry->fields(Story::class)['author']->schema());
        $this->assertSame(['type' => 'array', 'items' => ['type' => 'integer']], $registry->fields(Story::class)['genres']->schema());
        $this->assertSame(['person', 'story'], $registry->fields(Story::class)['picks']->schema()['items']['properties']['type']['enum']);

        /* A type that is not registered is refused the first time a reference
           to it is written or read. */
        $this->declare(Story::class, Genre::class);
        $this->assertThrows(fn () => Mainstay::create(Story::class, ['title' => 'Alone', 'slug' => 'alone', 'author' => 1], locale: 'en', overrideAccess: true), InvalidArgumentException::class, Person::class.' is not a registered content type.');
    }

    #[Test]
    public function a_read_refuses_a_reference_to_a_type_that_is_not_registered_at_any_depth(): void
    {
        $story = $this->story('Holder', ['author' => $this->person('Ann')]);

        /* Asked of the registry declared here: the facade keeps the one it
           first resolved. */
        $this->declare(Story::class, Genre::class);

        foreach ([0, 1] as $depth) {
            $this->assertThrows(fn () => app(Registry::class)->findById(Story::class, $story->id, locale: 'en', depth: $depth), InvalidArgumentException::class, Person::class.' is not a registered content type.');
        }
    }

    #[Test]
    public function terms_of_a_taxonomy_that_is_not_registered_are_refused_before_anything_is_written(): void
    {
        /* It has no pivot: refused by the schema, by a write before its row,
           and by a read before its query. */
        $this->declare(Person::class, Story::class);

        $this->assertThrows(fn () => app(ContentSchema::class)->diff(), InvalidArgumentException::class, 'Story::$genres holds '.Genre::class."'s terms, and ".Genre::class.' is not registered');
        $this->assertThrows(fn () => Mainstay::create(Story::class, ['title' => 'Alone', 'slug' => 'alone'], locale: 'en', overrideAccess: true), InvalidArgumentException::class, Genre::class.' is not a registered content type.');
        $this->assertThrows(fn () => Mainstay::find(Story::class), InvalidArgumentException::class, Genre::class.' is not a registered content type.');
        $this->assertSame(0, DB::table('story')->count());
    }

    #[Test]
    public function the_drift_check_names_each_key_the_pivot_is_without(): void
    {
        $this->artisan('mainstay:schema:check')->assertSuccessful();

        Schema::table('genre_entries', fn ($table) => $table->dropIndex('genre_entries_entry'));
        $this->artisan('mainstay:schema:check')->expectsOutputToContain('genre_entries: declared an index on (entry_type, entry_id), and the database has no such index')->assertFailed();
        $this->artisan('mainstay:sync')->assertSuccessful();

        /* An index leading with the other column serves other queries. */
        Schema::table('genre_entries', fn ($table) => $table->dropIndex('genre_entries_entry'));
        Schema::table('genre_entries', fn ($table) => $table->index(['entry_id', 'entry_type'], 'genre_entries_turned'));
        $this->artisan('mainstay:schema:check')->expectsOutputToContain('genre_entries: declared an index on (entry_type, entry_id), and the database has no such index')->assertFailed();
        $this->artisan('mainstay:sync')->assertSuccessful();

        Schema::table('genre_entries', fn ($table) => $table->dropForeign(['term_id']));
        $this->artisan('mainstay:schema:check')->expectsOutputToContain('genre_entries: declared a foreign key on (term_id) referencing genre.id, and the database has no such key')->assertFailed();
        $this->artisan('mainstay:sync')->assertSuccessful();

        /* MySQL keeps the unique index while the foreign key leans on it, so
           the key comes off first and goes back on with an index of its own. */
        $this->withoutUnique('genre_entries', 'genre_entries_unique', 'term_id', 'genre');
        $this->artisan('mainstay:schema:check')->expectsOutputToContain('genre_entries: declared unique on (term_id, entry_type, entry_id), and the database has no such index')->assertFailed();
        $this->artisan('mainstay:sync')->assertSuccessful();

        /* A global's one row per site is a key like these. */
        $this->withoutUnique('masthead', 'masthead_site_id_unique', 'site_id', 'sites');
        $this->artisan('mainstay:schema:check')->expectsOutputToContain('masthead: declared unique on (site_id), and the database has no such index')->assertFailed();
        $this->artisan('mainstay:sync')->assertSuccessful();

        $this->artisan('mainstay:schema:check')->assertSuccessful();
    }
}
