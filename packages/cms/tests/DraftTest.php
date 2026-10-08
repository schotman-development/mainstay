<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Gate as Signed;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Drafted\About;
use Mainstay\Tests\Fixtures\Drafted\Bulletin;
use Mainstay\Tests\Fixtures\Drafted\Leaflet;
use Mainstay\Tests\Fixtures\Drafted\Printed;
use Mainstay\Tests\Fixtures\Drafted\Section;
use Mainstay\Tests\Fixtures\Drafted\Sheet;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Masthead;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Policies\EditorPolicy;
use Mainstay\Tests\Fixtures\Policies\PublisherPolicy;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 8 check: drafts beside what is live, kept as what differs from
 | it and put live by publish; the revisions a replaced version leaves, and
 | a revision made a draft again; and the trash, restored from with its
 | paths back or emptied for good.
 */
class DraftTest extends DatabaseTestCase
{
    /** @var list<string> */
    private array $files = [];

    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Person::class, Story::class, Genre::class, Masthead::class, Bulletin::class, Sheet::class, Section::class, Printed::class, About::class, Leaflet::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));

        parent::tearDown();
    }

    private static function doc(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    /* An image row, as RelationTest writes one: the library's files are
       MediaTest's to check. */
    private function image(): int
    {
        return DB::table('mainstay_media')->insertGetId([
            'hash' => hash('sha256', Str::random()), 'type' => 'image/png', 'name' => 'cover.png', 'bytes' => 1, 'width' => 64, 'height' => 64,
            'focal_x' => 50, 'focal_y' => 50, 'alt' => json_encode(['en' => 'A cover', 'nl' => 'Een omslag']),
            'created_at' => '2026-10-07 00:00:00', 'updated_at' => '2026-10-07 00:00:00',
        ]);
    }

    private function story(string $title): Story
    {
        return Mainstay::create(Story::class, ['title' => $title, 'slug' => Str::slug($title)], locale: 'en', overrideAccess: true);
    }

    /* Every field of a bulletin it can hold, in English and Dutch, both at
       `$slug`, pointing at a story, a person and a genre of its own. */
    private function bulletin(string $slug = 'launch', array $data = []): Bulletin
    {
        $n = ++$this->made;
        $story = $this->story("Told {$n}");
        $person = Mainstay::create(Person::class, ['title' => 'Ada', 'slug' => "ada-{$n}"], locale: 'en', overrideAccess: true);
        $genre = Mainstay::create(Genre::class, ['title' => 'Noir', 'slug' => "noir-{$n}"], locale: 'en', overrideAccess: true);

        $entry = Mainstay::create(Bulletin::class, [
            'title' => 'Launch', 'slug' => $slug, 'body' => self::doc('Hello'), 'publishedOn' => '2026-10-07', 'kind' => 'news', 'approved' => true,
            'blocks' => [['type' => 'shelf', 'data' => ['spines' => [['type' => 'spine', 'data' => ['story' => $story->id]]]]]],
            'picks' => [['type' => 'person', 'id' => $person->id], $story],
            'cover' => $this->image(), 'genres' => [$genre], 'memo' => 'Quiet',
            ...$data,
        ], locale: 'en', overrideAccess: true);

        Mainstay::update(Bulletin::class, $entry->id, ['title' => 'Lancering', 'slug' => $slug, 'body' => self::doc('Hallo')], locale: 'nl', overrideAccess: true);

        return $entry;
    }

    /* What a bulletin's form would post: every field, as a read hands it
       out. */
    private function posted(Bulletin $read): array
    {
        return array_map(fn (string $name) => $read->{$name}, array_combine($names = array_keys(Mainstay::fields(Bulletin::class)), $names));
    }

    #[Test]
    public function the_live_version_names_who_published_it_and_each_revision_who_had(): void
    {
        $role = Role::query()->where('name', 'administrator')->sole();
        [$ada, $bo, $cy] = array_map(fn (string $name) => User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]), ['Ada', 'Bo', 'Cy']);
        $as = fn (?User $user) => $this->app['request']->attributes->set(Signed::USER, $user);
        $by = fn (string $type, int $id) => Mainstay::findById($type, $id, locale: 'en', overrideAccess: true)->publishedBy;

        $as($ada);
        $story = Mainstay::create(Story::class, ['title' => 'One', 'slug' => 'one'], locale: 'en');
        $this->assertSame($ada->id, $by(Story::class, $story->id));
        $genre = Mainstay::create(Genre::class, ['title' => 'Noir', 'slug' => 'noir'], locale: 'en');
        $this->assertSame($ada->id, $by(Genre::class, $genre->id), 'A term names its writer too.');

        /* A write that changes nothing names nobody new. */
        $as($bo);
        Mainstay::update(Story::class, $story->id, ['title' => 'One'], locale: 'en');
        $this->assertSame($ada->id, $by(Story::class, $story->id));

        Mainstay::update(Genre::class, $genre->id, ['title' => 'Noir, darker'], locale: 'en');
        $this->assertSame($bo->id, $by(Genre::class, $genre->id), 'And its next writer.');

        /* A publish replacing Ada's version names Bo, and files hers under
           her name. */
        Mainstay::drafts()->publish(Mainstay::drafts()->save(Story::class, ['title' => 'One, again'], entry: $story->id, locale: 'en')->id);
        $this->assertSame($bo->id, $by(Story::class, $story->id));
        $this->assertSame([$ada->id], Mainstay::revisions()->of(Story::class, $story->id)->pluck('publishedBy')->all());

        /* A translation added names Cy, and replaces nothing to file. */
        $as($cy);
        Mainstay::update(Story::class, $story->id, ['title' => 'Een', 'slug' => 'een'], locale: 'nl');
        $this->assertSame($cy->id, $by(Story::class, $story->id));
        $this->assertCount(1, Mainstay::revisions()->of(Story::class, $story->id));

        /* With two languages, a write that changes nothing still names
           nobody new, whatever order the driver reads them back in: with its
           statistics, Postgres scans the table in the order rows lie, which
           an update moves. */
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('analyze story_locales');
        }

        $as($bo);
        Mainstay::update(Story::class, $story->id, ['title' => 'One, again'], locale: 'en');
        Mainstay::update(Story::class, $story->id, ['title' => 'Een'], locale: 'nl');
        $this->assertSame($cy->id, $by(Story::class, $story->id));
        $as($cy);

        /* Code on its own authority names nobody, whoever is signed in, and
           files the version Cy last changed under Cy. */
        Mainstay::update(Story::class, $story->id, ['title' => 'One, by a script'], locale: 'en', overrideAccess: true);
        $this->assertNull($by(Story::class, $story->id));
        $this->assertSame([$cy->id, $ada->id], Mainstay::revisions()->of(Story::class, $story->id, overrideAccess: true)->pluck('publishedBy')->all());
        $this->assertNull($by(Story::class, Mainstay::create(Story::class, ['title' => 'Two', 'slug' => 'two'], locale: 'en', overrideAccess: true)->id), 'A create on its own authority names nobody too.');

        /* Nor does publishing a draft on it. */
        Mainstay::drafts()->publish(Mainstay::drafts()->save(Story::class, ['title' => 'One, published by a script'], entry: $story->id, locale: 'en')->id, overrideAccess: true);
        $this->assertNull($by(Story::class, $story->id));

        /* A restore from the trash that moves the slug to a free path
           changes what is live: it names who restored it, and files the
           version it moved. One that moves nothing names nobody new. */
        Mainstay::delete(Story::class, $story->id, overrideAccess: true);
        $taken = Mainstay::create(Story::class, ['title' => 'Taken', 'slug' => 'one'], locale: 'en', overrideAccess: true);
        $as($bo);
        Mainstay::restore(Story::class, $story->id);
        $this->assertSame([$bo->id, 'one-2'], [$by(Story::class, $story->id), Mainstay::findById(Story::class, $story->id, locale: 'en')->slug]);
        Mainstay::revisions()->restore(Mainstay::revisions()->of(Story::class, $story->id)->first()->id);
        $this->assertSame('one', Mainstay::drafts()->of(Story::class, $story->id, locale: 'en')->entry->slug, 'The version before the move is kept.');
        Mainstay::drafts()->discard(Mainstay::drafts()->of(Story::class, $story->id, locale: 'en')->id);
        Mainstay::delete(Story::class, $taken->id, overrideAccess: true);
        Mainstay::delete(Story::class, $story->id, overrideAccess: true);
        $as($cy);
        Mainstay::restore(Story::class, $story->id);
        $this->assertSame($bo->id, $by(Story::class, $story->id));

        /* A new entry's first publish names who published it, and a
           global's save who saved it, each nobody on code's own authority. */
        $as($ada);
        $first = Mainstay::drafts()->publish(Mainstay::drafts()->save(Story::class, ['title' => 'Three', 'slug' => 'three'], locale: 'en')->id);
        $this->assertSame($ada->id, $by(Story::class, $first->id));
        $masthead = fn () => Mainstay::global(Masthead::class, locale: 'en', overrideAccess: true)->publishedBy;
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'First'], locale: 'en');
        $this->assertSame($ada->id, $masthead());
        $as($bo);
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Second'], locale: 'en');
        $this->assertSame($bo->id, $masthead());
        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Third'], locale: 'en', overrideAccess: true);
        $this->assertNull($masthead());
    }

    #[Test]
    public function a_front_page_moved_off_its_path_names_who_moved_it_and_keeps_what_it_moved(): void
    {
        $role = Role::query()->where('name', 'administrator')->sole();
        [$ada, $bo] = array_map(fn (string $name) => User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => 'correct horse battery', 'role_id' => $role->id]), ['Ada', 'Bo']);
        $as = fn (?User $user) => $this->app['request']->attributes->set(Signed::USER, $user);

        /* Ada's front page answers `/`, so a script can give another story
           its slug; making a third the front page moves hers to a free one. */
        $as($ada);
        $home = Mainstay::create(Story::class, ['title' => 'Home', 'slug' => 'home'], locale: 'en');
        Mainstay::setFrontPage(Story::class, $home->id);
        $as(null);
        Mainstay::create(Story::class, ['title' => 'Another home', 'slug' => 'home'], locale: 'en', overrideAccess: true);
        $front = Mainstay::create(Story::class, ['title' => 'Front', 'slug' => 'front'], locale: 'en', overrideAccess: true);
        $as($bo);
        Mainstay::setFrontPage(Story::class, $front->id);

        $moved = Mainstay::findById(Story::class, $home->id, locale: 'en');
        $this->assertSame(['home-2', $bo->id], [$moved->slug, $moved->publishedBy]);
        $this->assertSame([$ada->id], Mainstay::revisions()->of(Story::class, $home->id)->pluck('publishedBy')->all(), 'The version before the move is kept, under who had published it.');

        /* Moved on code's own authority, with Bo still signed in, it names
           nobody. */
        Mainstay::create(Story::class, ['title' => 'Another front', 'slug' => 'front'], locale: 'en', overrideAccess: true);
        Mainstay::setFrontPage(Story::class, $home->id, overrideAccess: true);
        $this->assertSame(['front-2', null], [Mainstay::findById(Story::class, $front->id, locale: 'en')->slug, Mainstay::findById(Story::class, $front->id, locale: 'en')->publishedBy]);
    }

    #[Test]
    public function every_draft_of_a_type_is_listed_newest_first_save_a_trashed_entrys(): void
    {
        $live = $this->story('Live');
        $trashed = $this->story('Trashed');
        Mainstay::drafts()->save(Story::class, ['title' => 'Trashed, changed'], entry: $trashed->id, locale: 'en', overrideAccess: true);
        Mainstay::delete(Story::class, $trashed->id, overrideAccess: true);

        $changed = Mainstay::drafts()->save(Story::class, ['title' => 'Live, changed'], entry: $live->id, locale: 'en', overrideAccess: true);
        $new = Mainstay::drafts()->save(Story::class, ['title' => 'New', 'slug' => 'new'], locale: 'en', overrideAccess: true);
        $dutch = Mainstay::drafts()->save(Story::class, ['title' => 'Nieuw', 'slug' => 'nieuw'], locale: 'nl', overrideAccess: true);
        Mainstay::drafts()->save(Person::class, ['title' => 'Someone else', 'slug' => 'someone'], locale: 'en', overrideAccess: true);
        DB::table('mainstay_drafts')->where('id', $changed->id)->update(['updated_at' => now('UTC')->addDay()->format('Y-m-d H:i:s')]);

        $all = Mainstay::drafts()->all(Story::class, locale: 'en', overrideAccess: true);
        $this->assertSame([$changed->id, $new->id], $all->pluck('id')->all());
        $this->assertSame([$live->id, null], $all->pluck('entryId')->all());
        $this->assertSame(['Live, changed', 'New'], $all->map(fn ($draft) => $draft->entry->title)->all());

        $this->assertSame([$dutch->id], Mainstay::drafts()->all(Story::class, locale: 'nl', overrideAccess: true)->pluck('id')->all(), 'In the locale each holds.');
        $this->assertThrows(fn () => Mainstay::drafts()->all(Genre::class, overrideAccess: true), InvalidArgumentException::class, 'whose terms are written live');
    }

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

    /* `$run` once on its own and once inside a caller's transaction whose
       snapshot was taken before the other connection wrote, each after
       `$setup`, which commits before the caller's transaction starts. */
    private function twice(callable $setup, callable $run): void
    {
        config()->set('database.connections.beside', config('database.connections.testing'));

        $setup();
        $run();
        $setup();

        DB::beginTransaction();
        DB::table('sites')->count();

        try {
            $run();
            $this->assertSame(1, DB::table('sites')->count(), 'The caller\'s transaction goes on.');
        } finally {
            DB::commit();
        }
    }

    #[Test]
    public function a_new_entry_is_a_draft_until_it_is_published(): void
    {
        $draft = Mainstay::drafts()->save(Bulletin::class, [
            'title' => 'Launch', 'slug' => 'launch', 'body' => null, 'publishedOn' => null, 'kind' => null, 'approved' => false, 'picks' => [],
            'blocks' => [['type' => 'callout', 'data' => ['heading' => '']]],
        ], locale: 'en', overrideAccess: true);

        $this->assertNull($draft->entryId);
        $this->assertSame('Launch', $draft->entry->title);
        $this->assertFalse(isset($draft->entry->id), 'No id until it is published.');
        $this->assertFalse(isset($draft->entry->body) || isset($draft->entry->publishedOn) || isset($draft->entry->kind), 'What is left empty and has no empty value is absent, not invented.');
        $this->assertFalse($draft->entry->approved);
        $this->assertEqualsCanonicalizing(['publishedOn', 'kind', 'approved', 'picks', 'blocks'], $draft->fields);
        $this->assertEqualsCanonicalizing(['title', 'slug', 'body'], $draft->locales['en']);

        $this->assertCount(0, Mainstay::find(Bulletin::class, locale: 'en'));
        $this->assertSame(0, Mainstay::paginate(Bulletin::class, locale: 'en')->total());
        $this->assertNull(Mainstay::findByUri('/posts/launch', locale: 'en'));
        $this->assertSame([0, 0], [DB::table('bulletin')->count(), DB::table('uris')->count()]);

        $this->assertEqualsCanonicalizing(['body', 'publishedOn', 'kind', 'approved', 'picks', 'blocks.0.data.heading'], array_keys($this->refusal(fn () => Mainstay::drafts()->publish($draft->id, overrideAccess: true))), 'Required back at every level, a block\'s own fields too.');
        $this->assertSame([0, 1], [DB::table('bulletin')->count(), DB::table('mainstay_drafts')->count()], 'Nothing written, and the draft kept.');

        Mainstay::drafts()->save(Bulletin::class, [
            'body' => self::doc('Hello'), 'publishedOn' => '2026-10-07', 'kind' => 'news', 'approved' => true, 'picks' => [$this->story('Told')],
            'blocks' => [['type' => 'callout', 'data' => ['heading' => 'Hi']]],
        ], draft: $draft->id, locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Bulletin::class, ['title' => 'Lancering', 'slug' => 'lancering', 'body' => self::doc('Hallo')], draft: $draft->id, locale: 'nl', overrideAccess: true);

        $entry = Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $this->assertSame(['Launch', '/posts/launch'], [$entry->title, $entry->uri]);
        $this->assertSame('Lancering', Mainstay::findByUri('/berichten/lancering', locale: 'nl')->title);
        $this->assertSame([0, 0], [DB::table('mainstay_drafts')->count(), DB::table('mainstay_revisions')->count()], 'The draft gone, and nothing outgoing to file.');
    }

    #[Test]
    public function new_drafts_of_one_type_stand_side_by_side_and_hold_their_shape(): void
    {
        Mainstay::drafts()->save(Bulletin::class, ['title' => 'One'], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Bulletin::class, ['title' => 'Two'], locale: 'en', overrideAccess: true);

        $this->assertSame(2, DB::table('mainstay_drafts')->whereNull('entry_id')->count());

        /* Only presence gives way: what keeps a value readable does not. */
        $this->assertArrayHasKey('blocks.0.type', $this->refusal(fn () => Mainstay::drafts()->save(Bulletin::class, ['blocks' => [['data' => []]]], locale: 'en', overrideAccess: true)));
        $this->assertArrayHasKey('picks.0.id', $this->refusal(fn () => Mainstay::drafts()->save(Bulletin::class, ['picks' => [['type' => 'person']]], locale: 'en', overrideAccess: true)));
        $this->assertArrayHasKey('kind', $this->refusal(fn () => Mainstay::drafts()->save(Bulletin::class, ['kind' => 'gossip'], locale: 'en', overrideAccess: true)));
        $this->assertArrayHasKey('picks.0', $this->refusal(fn () => Mainstay::drafts()->save(Bulletin::class, ['picks' => [['type' => 'story', 'id' => 999]]], locale: 'en', overrideAccess: true)), 'What it names has to be there.');
    }

    #[Test]
    public function a_publish_refused_writes_nothing(): void
    {
        $this->bulletin('taken');
        $story = $this->story('Gone');

        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Again', 'slug' => 'taken', 'body' => self::doc('Hi'), 'publishedOn' => '2026-10-07', 'kind' => 'news', 'approved' => true, 'picks' => [$story]], locale: 'en', overrideAccess: true);

        $this->assertSame(['slug' => ['The path /posts/taken is already taken in en.']], $this->refusal(fn () => Mainstay::drafts()->publish($draft->id, overrideAccess: true)));

        Mainstay::drafts()->save(Bulletin::class, ['slug' => 'fresh'], draft: $draft->id, locale: 'en', overrideAccess: true);
        Mainstay::delete(Story::class, $story->id, overrideAccess: true);

        $this->assertSame(['picks.0'], array_keys($this->refusal(fn () => Mainstay::drafts()->publish($draft->id, overrideAccess: true))), 'A target trashed since the draft named it.');
        $this->assertSame([1, 1], [DB::table('bulletin')->count(), DB::table('mainstay_drafts')->count()]);
    }

    #[Test]
    public function a_draft_keeps_only_what_differs_from_live(): void
    {
        $entry = $this->bulletin();
        $read = Mainstay::findById(Bulletin::class, $entry->id, locale: 'en', overrideAccess: true);

        /* A form posts every field, a document, blocks two deep and a list
           of several types among them, as the read handed them out. */
        $draft = Mainstay::drafts()->save(Bulletin::class, [...$this->posted($read), 'title' => 'Launched'], entry: $entry->id, locale: 'en', overrideAccess: true);

        $this->assertSame([[], ['en' => ['title']]], [$draft->fields, $draft->locales]);
        $this->assertSame($entry->id, $draft->entryId);

        $this->assertNull(Mainstay::drafts()->save(Bulletin::class, $this->posted($read), entry: $entry->id, locale: 'en', overrideAccess: true), 'Nothing left differing.');
        $this->assertNull(Mainstay::drafts()->save(Bulletin::class, ['body' => ['content' => [['content' => [['text' => 'Hello', 'type' => 'text']], 'type' => 'paragraph']], 'type' => 'doc']], entry: $entry->id, locale: 'en', overrideAccess: true), 'A document compared in any key order.');
        $this->assertSame(0, DB::table('mainstay_drafts')->count());

        /* Shared and in one language, read in each over what is live. */
        Mainstay::drafts()->save(Bulletin::class, ['kind' => 'note'], entry: $entry->id, locale: 'en', overrideAccess: true);
        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Lanceren'], entry: $entry->id, locale: 'nl', overrideAccess: true);

        $this->assertSame([['kind'], ['nl' => ['title']]], [$draft->fields, $draft->locales]);
        $this->assertSame(['Launch', 'note'], [Mainstay::drafts()->of(Bulletin::class, $entry->id, locale: 'en', overrideAccess: true)->entry->title, $draft->entry->kind]);
        $this->assertSame(['Lanceren', '/berichten/launch'], [$draft->entry->title, $draft->entry->uri]);
        $this->assertSame('Ada', Mainstay::drafts()->find($draft->id, locale: 'en', overrideAccess: true)->entry->picks[0]->title, 'What it points at loaded, as a read loads it.');
        $this->assertSame(['Launch', 'news', 'Lancering'], [Mainstay::findById(Bulletin::class, $entry->id, locale: 'en')->title, Mainstay::findById(Bulletin::class, $entry->id, locale: 'en')->kind, Mainstay::findById(Bulletin::class, $entry->id, locale: 'nl')->title], 'The site reads what is live.');

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $this->assertSame(['note', 'Lanceren'], [Mainstay::findById(Bulletin::class, $entry->id, locale: 'en')->kind, Mainstay::findById(Bulletin::class, $entry->id, locale: 'nl')->title]);
        $snapshot = json_decode(DB::table('mainstay_revisions')->sole()->snapshot, true);
        $this->assertSame(['news', 'Launch', 'Lancering'], [$snapshot['fields']['kind'], $snapshot['locales']['en']['title'], $snapshot['locales']['nl']['title']], 'One revision, of every locale.');

        /* Only shared, published in the first language the entry has. */
        $draft = Mainstay::drafts()->save(Bulletin::class, ['kind' => 'news'], entry: $entry->id, locale: 'nl', overrideAccess: true);
        $this->assertSame([], $draft->locales);
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $this->assertSame('news', Mainstay::findById(Bulletin::class, $entry->id, locale: 'nl')->kind);
    }

    #[Test]
    public function a_draft_names_a_translation_as_update_does(): void
    {
        $entry = Mainstay::create(Story::class, ['title' => 'Told', 'slug' => 'told'], locale: 'en', overrideAccess: true);
        App::setLocale('nl');

        $this->assertThrows(fn () => Mainstay::drafts()->save(Story::class, ['title' => 'Verteld'], entry: $entry->id, overrideAccess: true), RecordNotFoundException::class, "Pass locale: 'nl'");

        Mainstay::drafts()->save(Story::class, ['title' => 'Verteld', 'slug' => 'verteld'], entry: $entry->id, locale: 'nl', overrideAccess: true);
        $draft = Mainstay::drafts()->save(Story::class, ['title' => 'Verteld!'], entry: $entry->id, overrideAccess: true);

        $this->assertSame('Verteld!', $draft->entry->title, 'Once drafted, the request\'s locale finds it.');
        $this->assertNull(Mainstay::findById(Story::class, $entry->id, locale: 'nl'));

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $this->assertSame(['Verteld!', '/stories/verteld'], [Mainstay::findById(Story::class, $entry->id, locale: 'nl')->title, Mainstay::findById(Story::class, $entry->id, locale: 'nl')->uri]);
    }

    #[Test]
    public function a_draft_adds_a_translation_with_nothing_in_it_filled_in(): void
    {
        $sheet = Mainstay::create(Sheet::class, ['title' => 'Notes', 'slug' => 'notes'], locale: 'en', overrideAccess: true);
        $draft = Mainstay::drafts()->save(Sheet::class, ['slug' => 'blad'], entry: $sheet->id, locale: 'nl', overrideAccess: true);

        $this->assertSame([['slug'], ['nl' => []]], [$draft->fields, $draft->locales]);
        $this->assertSame('blad', Mainstay::drafts()->find($draft->id, locale: 'nl', overrideAccess: true)->entry->slug);
        $this->assertSame(['title'], array_keys($this->refusal(fn () => Mainstay::drafts()->publish($draft->id, overrideAccess: true))), 'Published, it is a Dutch translation, and needs its title.');
    }

    #[Test]
    public function a_direct_write_meanwhile_keeps_what_the_draft_did_not_change(): void
    {
        $entry = $this->bulletin();
        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine', 'kind' => 'note'], entry: $entry->id, locale: 'en', overrideAccess: true);

        Mainstay::update(Bulletin::class, $entry->id, ['title' => 'Theirs', 'publishedOn' => '2026-10-08'], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $read = Mainstay::findById(Bulletin::class, $entry->id, locale: 'en');
        $this->assertSame(['Mine', 'note', '2026-10-08'], [$read->title, $read->kind, $read->publishedOn->toDateString()]);
    }

    #[Test]
    public function two_saves_of_one_draft_merge(): void
    {
        $entry = $this->bulletin();

        $draft = null;

        $this->twice(function () use ($entry, &$draft) {
            DB::table('mainstay_drafts')->delete();
            $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine'], entry: $entry->id, locale: 'en', overrideAccess: true);
        }, function () use ($entry, &$draft) {
            $raced = null;

            /* Once this save has looked the draft up, another commits a
               change to it. */
            DB::listen(function ($query) use (&$raced, $draft) {
                if ($raced === null && str_contains($query->sql, 'mainstay_drafts') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                    $raced = false;
                    $raced = $this->beside(fn () => DB::connection('beside')->table('mainstay_drafts')->where('id', $draft->id)
                        ->update(['changes' => json_encode(['fields' => ['kind' => 'note'], 'locales' => ['en' => ['title' => 'Mine']]])]));
                }
            });

            $merged = Mainstay::drafts()->save(Bulletin::class, ['slug' => 'mine'], entry: $entry->id, locale: 'en', overrideAccess: true);
            $this->assertNotNull($raced, 'The other save ran.');

            $this->assertSame($raced ? ['kind'] : [], $merged->fields);
            $this->assertEqualsCanonicalizing(['title', 'slug'], $merged->locales['en']);
        });
    }

    #[Test]
    public function two_first_saves_of_a_draft_leave_one(): void
    {
        $entry = $this->bulletin();

        $this->twice(fn () => DB::table('mainstay_drafts')->delete(), function () use ($entry) {
            $raced = null;

            /* Once this save has looked for the draft and found none,
               another writes it. */
            DB::listen(function ($query) use (&$raced, $entry) {
                if ($raced === null && str_contains($query->sql, 'mainstay_drafts') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                    $raced = false;
                    $raced = $this->beside(fn () => DB::connection('beside')->table('mainstay_drafts')->insert([
                        'site_id' => 1, 'type' => 'bulletin', 'entry_id' => $entry->id, 'changes' => json_encode(['fields' => ['kind' => 'note'], 'locales' => []]),
                        'created_at' => '2026-10-07 00:00:00', 'updated_at' => '2026-10-07 00:00:00',
                    ]));
                }
            });

            $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine'], entry: $entry->id, locale: 'en', overrideAccess: true);

            $this->assertNotNull($raced, 'The other save ran.');
            $this->assertSame(1, DB::table('mainstay_drafts')->count());
            $this->assertSame([$raced ? ['kind'] : [], ['en' => ['title']]], [$draft->fields, $draft->locales], 'The other save\'s draft, merged into.');
        });
    }

    #[Test]
    public function a_write_that_replaces_something_live_files_it(): void
    {
        $entry = $this->bulletin();
        $this->assertSame(0, DB::table('mainstay_revisions')->count(), 'A create and a translation added replace nothing.');

        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note'], locale: 'en', overrideAccess: true);
        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note'], locale: 'en', overrideAccess: true);
        $genre = Mainstay::find(Genre::class, locale: 'en')->first();
        Mainstay::update(Genre::class, $genre->id, ['title' => 'Crime'], locale: 'en', overrideAccess: true);

        $this->assertSame(1, DB::table('mainstay_revisions')->count(), 'Nothing for a write changing nothing, or for a term.');
        $this->assertSame('news', json_decode(DB::table('mainstay_revisions')->value('snapshot'), true)['fields']['kind']);

        config()->set('mainstay.revisions', 2);
        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'news'], locale: 'en', overrideAccess: true);
        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note'], locale: 'en', overrideAccess: true);

        $revisions = Mainstay::revisions()->of(Bulletin::class, $entry->id, overrideAccess: true);
        $this->assertSame(DB::table('mainstay_revisions')->orderByDesc('id')->pluck('id')->map(fn ($id) => (int) $id)->all(), $revisions->pluck('id')->all());
        $this->assertCount(2, $revisions, 'Pruned to the newest two.');
        $this->assertSame([$entry->id, 'note'], [$revisions[0]->entryId, json_decode(DB::table('mainstay_revisions')->where('id', $revisions[1]->id)->value('snapshot'), true)['fields']['kind']]);
    }

    #[Test]
    public function a_revision_restored_is_a_draft_of_what_differs(): void
    {
        $entry = $this->bulletin();
        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note', 'publishedOn' => '2026-10-08'], locale: 'en', overrideAccess: true);
        Mainstay::update(Bulletin::class, $entry->id, ['title' => 'Relaunched', 'cover' => $cover = $this->image()], locale: 'en', overrideAccess: true);

        /* The oldest, as an older declaration would have left it: a field
           since removed, a value since refused, a field since added. */
        $revision = Mainstay::revisions()->of(Bulletin::class, $entry->id, overrideAccess: true)->last();
        $snapshot = json_decode(DB::table('mainstay_revisions')->where('id', $revision->id)->value('snapshot'), true);
        $snapshot['fields'] = [...$snapshot['fields'], 'subtitle' => 'Gone', 'kind' => 'feature'];
        unset($snapshot['fields']['cover']);
        DB::table('mainstay_revisions')->where('id', $revision->id)->update(['snapshot' => json_encode($snapshot)]);

        $draft = Mainstay::revisions()->restore($revision->id, overrideAccess: true);

        $this->assertSame('The type no longer declares it.', $draft->unrestored['fields']['subtitle']);
        $this->assertArrayHasKey('kind', $draft->unrestored['fields']);
        $this->assertSame([['publishedOn'], ['en' => ['title']]], [$draft->fields, $draft->locales]);

        Mainstay::drafts()->publish($draft->id, overrideAccess: true);

        $read = Mainstay::findById(Bulletin::class, $entry->id, locale: 'en');
        $this->assertSame(['Launch', '2026-10-07', 'note', $cover], [$read->title, $read->publishedOn->toDateString(), $read->kind, $read->cover->id]);
    }

    #[Test]
    public function a_global_is_drafted_before_and_after_it_is_written(): void
    {
        $draft = Mainstay::drafts()->save(Masthead::class, ['motto' => 'Read on'], locale: 'en', overrideAccess: true);

        $this->assertNull(Mainstay::global(Masthead::class, locale: 'en'));
        $this->assertSame([null, 'Read on', 0], [$draft->entryId, $draft->entry->motto, (int) DB::table('mainstay_drafts')->value('entry_id')]);
        $this->assertSame($draft->id, Mainstay::drafts()->of(Masthead::class, locale: 'en', overrideAccess: true)->id);

        $this->assertSame('Read on', Mainstay::drafts()->publish($draft->id, overrideAccess: true)->motto);
        $this->assertSame(1, DB::table('masthead')->count());

        Mainstay::saveGlobal(Masthead::class, ['motto' => 'Read more'], locale: 'en', overrideAccess: true);
        $this->assertSame([1, 0], [Mainstay::revisions()->of(Masthead::class, overrideAccess: true)->count(), (int) DB::table('mainstay_revisions')->value('entry_id')]);
    }

    #[Test]
    public function terms_have_no_drafts_or_revisions(): void
    {
        $this->assertThrows(fn () => Mainstay::drafts()->save(Genre::class, ['title' => 'Noir'], locale: 'en', overrideAccess: true), InvalidArgumentException::class, 'written live');
        $this->assertThrows(fn () => Mainstay::revisions()->of(Genre::class, 1, overrideAccess: true), InvalidArgumentException::class, 'written live');
    }

    #[Test]
    public function an_internal_change_is_hidden_from_and_not_published_by_who_may_not_see_it(): void
    {
        Gate::policy(Bulletin::class, PublisherPolicy::class);
        $entry = $this->bulletin();

        Mainstay::drafts()->save(Bulletin::class, ['memo' => 'Louder'], entry: $entry->id, locale: 'en', overrideAccess: true);
        $draft = Mainstay::drafts()->of(Bulletin::class, $entry->id, locale: 'en');

        $this->assertFalse(isset($draft->entry->memo));
        $this->assertSame([], $draft->fields);
        $this->assertThrows(fn () => Mainstay::drafts()->publish($draft->id), AuthorizationException::class, 'may not see');
        $this->assertThrows(fn () => Mainstay::drafts()->save(Bulletin::class, ['memo' => 'Mine'], entry: $entry->id, locale: 'en'), InvalidArgumentException::class, 'no field called memo');

        Mainstay::drafts()->discard($draft->id);
        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Theirs'], entry: $entry->id, locale: 'en');
        $this->assertSame('Theirs', Mainstay::drafts()->publish($draft->id)->title, 'One that changes nothing hidden, published.');

        /* A revision differing from live only where they may not look
           brings nothing back to them, and names nothing. */
        Mainstay::update(Bulletin::class, $entry->id, ['memo' => 'Loud'], locale: 'en', overrideAccess: true);
        $this->assertNull(Mainstay::revisions()->restore(Mainstay::revisions()->of(Bulletin::class, $entry->id)->first()->id));
        $this->assertSame(0, DB::table('mainstay_drafts')->count());
    }

    #[Test]
    public function a_publisher_puts_a_new_entry_live(): void
    {
        Gate::policy(Bulletin::class, PublisherPolicy::class);

        $draft = Mainstay::drafts()->save(Bulletin::class, [
            'title' => 'Fresh', 'slug' => 'fresh', 'body' => self::doc('Hi'), 'publishedOn' => '2026-10-07', 'kind' => 'news', 'approved' => true, 'picks' => [$this->story('Told')],
        ], locale: 'en');

        $this->assertSame('/posts/fresh', Mainstay::drafts()->publish($draft->id)->uri, 'Asked about the type, there being no entry yet.');
    }

    #[Test]
    public function a_publish_puts_live_what_the_draft_holds_when_it_runs(): void
    {
        $entry = $this->bulletin();
        $draft = null;

        $this->twice(function () use ($entry, &$draft) {
            DB::table('mainstay_drafts')->delete();
            $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine'], entry: $entry->id, locale: 'en', overrideAccess: true);
        }, function () use ($entry, &$draft) {
            $raced = null;

            /* Once the publish has looked the draft up, a save commits a
               newer title to it. */
            DB::listen(function ($query) use (&$raced, $draft) {
                if ($raced === null && str_contains($query->sql, 'mainstay_drafts') && str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                    $raced = false;
                    $raced = $this->beside(fn () => DB::connection('beside')->table('mainstay_drafts')->where('id', $draft->id)
                        ->update(['changes' => json_encode(['fields' => [], 'locales' => ['en' => ['title' => 'Newer']]])]));
                }
            });

            Mainstay::drafts()->publish($draft->id, overrideAccess: true);

            $this->assertNotNull($raced, 'The other save ran.');
            $this->assertSame($raced ? 'Newer' : 'Mine', Mainstay::findById(Bulletin::class, $entry->id, locale: 'en')->title);
        });
    }

    #[Test]
    public function a_writer_may_draft_and_not_publish(): void
    {
        Gate::policy(Bulletin::class, EditorPolicy::class);
        $entry = $this->bulletin();

        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine'], entry: $entry->id, locale: 'en');

        $this->assertThrows(fn () => Mainstay::drafts()->publish($draft->id), AuthorizationException::class);
        $this->assertSame('Launch', Mainstay::findById(Bulletin::class, $entry->id, locale: 'en')->title);
    }

    #[Test]
    public function an_entry_restored_from_the_trash_takes_a_free_path(): void
    {
        $first = $this->bulletin();
        Mainstay::drafts()->save(Bulletin::class, ['title' => 'Kept'], entry: $first->id, locale: 'en', overrideAccess: true);
        Mainstay::delete(Bulletin::class, $first->id, overrideAccess: true);

        $other = $this->bulletin('other');
        Mainstay::update(Bulletin::class, $other->id, ['slug' => 'launch'], locale: 'en', overrideAccess: true);

        $this->assertEquals(['en' => '/posts/launch-2', 'nl' => '/berichten/launch'], Mainstay::restore(Bulletin::class, $first->id, overrideAccess: true));
        $this->assertSame(['launch-2', 'launch'], [Mainstay::findById(Bulletin::class, $first->id, locale: 'en')->slug, Mainstay::findById(Bulletin::class, $first->id, locale: 'nl')->slug]);
        $this->assertSame('Again', Mainstay::update(Bulletin::class, $first->id, ['title' => 'Again'], locale: 'en', overrideAccess: true)->title, 'Slug and path agree, so the next save works.');
        $this->assertSame('Kept', Mainstay::drafts()->of(Bulletin::class, $first->id, locale: 'en', overrideAccess: true)->entry->title, 'Its draft through the trash and back.');
        $this->assertThrows(fn () => Mainstay::restore(Bulletin::class, $first->id, overrideAccess: true), RecordNotFoundException::class, 'not in the trash');

        /* A slug at its field's max, cut short to fit. */
        $long = $this->bulletin('twelve-chars');
        Mainstay::delete(Bulletin::class, $long->id, overrideAccess: true);
        $this->bulletin('twelve-chars');
        $this->assertSame('/posts/twelve-cha-2', Mainstay::restore(Bulletin::class, $long->id, overrideAccess: true)['en']);

        /* A shared slug, moved in every language, to one free in all of
           them: -2 is taken in Dutch alone. */
        $sheet = Mainstay::create(Sheet::class, ['title' => 'Notes', 'slug' => 'notes'], locale: 'en', overrideAccess: true);
        Mainstay::update(Sheet::class, $sheet->id, ['title' => 'Aantekeningen'], locale: 'nl', overrideAccess: true);
        Mainstay::delete(Sheet::class, $sheet->id, overrideAccess: true);
        Mainstay::create(Sheet::class, ['title' => 'More notes', 'slug' => 'notes'], locale: 'en', overrideAccess: true);
        Mainstay::create(Sheet::class, ['title' => 'Andere', 'slug' => 'notes-2'], locale: 'nl', overrideAccess: true);
        $this->assertEquals(['en' => '/sheets/notes-3', 'nl' => '/bladen/notes-3'], Mainstay::restore(Sheet::class, $sheet->id, overrideAccess: true));
    }

    #[Test]
    public function a_path_no_suffix_frees_is_refused_and_the_entry_stays_in_the_trash(): void
    {
        foreach ([
            [Section::class, ['title' => 'News', 'kind' => 'news'], 'kind', '/sections/news'],
            [Printed::class, ['title' => 'Flyer', 'slug' => 'flyer'], 'slug', '/printed/flyer/copy'],
            [About::class, ['title' => 'About'], 'uri', '/about'],
        ] as [$type, $data, $field, $path]) {
            $trashed = Mainstay::create($type, $data, locale: 'en', overrideAccess: true);
            Mainstay::delete($type, $trashed->id, overrideAccess: true);
            Mainstay::create($type, $data, locale: 'en', overrideAccess: true);

            $this->assertSame([$field => ["The path {$path} is already taken in en."]], $this->refusal(fn () => Mainstay::restore($type, $trashed->id, overrideAccess: true)));
            $this->assertSame(1, DB::table($type::handle())->whereNotNull('deleted_at')->count());
        }

        /* A path a locale's prefix has come to answer. */
        $leaflet = Mainstay::create(Leaflet::class, ['title' => 'News', 'slug' => 'nieuws'], locale: 'en', overrideAccess: true);
        Mainstay::delete(Leaflet::class, $leaflet->id, overrideAccess: true);
        config()->set('mainstay.locales', ['en' => '/', 'nl' => '/nieuws']);

        $this->assertSame(['slug' => ["The path /nieuws is answered by nl's prefix /nieuws in en."]], $this->refusal(fn () => Mainstay::restore(Leaflet::class, $leaflet->id, overrideAccess: true)));
    }

    #[Test]
    public function an_entry_deleted_for_good_takes_everything_of_its_own(): void
    {
        $entry = $this->bulletin();
        $this->assertThrows(fn () => Mainstay::destroy(Bulletin::class, $entry->id, overrideAccess: true), RecordNotFoundException::class, 'not in the trash');

        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note'], locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Bulletin::class, ['title' => 'Draft'], entry: $entry->id, locale: 'en', overrideAccess: true);
        Mainstay::delete(Bulletin::class, $entry->id, overrideAccess: true);
        Mainstay::destroy(Bulletin::class, $entry->id, overrideAccess: true);

        $this->assertSame([0, 0, 0, 0, 0], [
            DB::table('bulletin')->count(), DB::table('bulletin_locales')->count(), DB::table('mainstay_drafts')->count(),
            DB::table('mainstay_revisions')->count(), DB::table('genre_entries')->where('entry_type', 'bulletin')->count(),
        ]);

        /* A term, with the rows pointing at it; and a target, read as
           missing where it was. */
        $other = $this->bulletin('other');
        $read = Mainstay::findById(Bulletin::class, $other->id, locale: 'en');
        [$genre, $story] = [$read->genres[0], $read->picks[1]];

        foreach ([[Genre::class, $genre->id], [Story::class, $story->id]] as [$type, $id]) {
            Mainstay::delete($type, $id, overrideAccess: true);
            Mainstay::destroy($type, $id, overrideAccess: true);
        }

        $read = Mainstay::findById(Bulletin::class, $other->id, locale: 'en');
        $this->assertSame([[], 0], [$read->genres, DB::table('genre_entries')->count()]);
        $this->assertSame([$story->id, true], [$read->picks[1]->id, $read->picks[1]->missing]);
    }

    #[Test]
    public function an_image_comes_back_from_the_trash_or_goes_for_good(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        $image = imagecreatetruecolor(8, 8);
        $this->files[] = $path = tempnam(sys_get_temp_dir(), 'mainstay-media-');
        imagepng($image, $path);

        $media = Mainstay::media()->upload($path, alt: ['en' => 'Black', 'nl' => 'Zwart'], overrideAccess: true);
        Mainstay::media()->delete($media->id, overrideAccess: true);

        $this->assertSame('Black', Mainstay::media()->restore($media->id, overrideAccess: true)->alt);

        Mainstay::media()->delete($media->id, overrideAccess: true);
        Mainstay::media()->destroy($media->id, overrideAccess: true);

        $this->assertSame(0, DB::table('mainstay_media')->count());
        $this->assertInstanceOf(Media::class, Mainstay::media()->upload($path, alt: ['en' => 'Black', 'nl' => 'Zwart'], overrideAccess: true), 'Its bytes uploaded again.');
        $this->assertThrows(fn () => Mainstay::media()->destroy(999, overrideAccess: true), RecordNotFoundException::class, 'trash');
    }

    #[Test]
    public function every_call_is_refused_without_the_override_until_there_is_a_user(): void
    {
        $entry = $this->bulletin();
        $draft = Mainstay::drafts()->save(Bulletin::class, ['title' => 'Draft'], entry: $entry->id, locale: 'en', overrideAccess: true);
        Mainstay::update(Bulletin::class, $entry->id, ['kind' => 'note'], locale: 'en', overrideAccess: true);
        $revision = Mainstay::revisions()->of(Bulletin::class, $entry->id, overrideAccess: true)->sole();
        $trashed = $this->bulletin('trashed');
        Mainstay::delete(Bulletin::class, $trashed->id, overrideAccess: true);
        $image = $this->image();
        Mainstay::media()->delete($image, overrideAccess: true);
        $undrafted = $this->bulletin('plain');

        foreach ([
            fn () => Mainstay::drafts()->save(Bulletin::class, ['title' => 'New'], locale: 'en'),
            fn () => Mainstay::drafts()->save(Bulletin::class, ['title' => 'Mine'], entry: $entry->id, locale: 'en'),
            fn () => Mainstay::drafts()->save(Masthead::class, ['motto' => 'Mine'], locale: 'en'),
            fn () => Mainstay::drafts()->find($draft->id),
            fn () => Mainstay::drafts()->of(Bulletin::class, $entry->id),
            fn () => Mainstay::drafts()->of(Bulletin::class, $undrafted->id),
            fn () => Mainstay::drafts()->of(Masthead::class),
            fn () => Mainstay::drafts()->publish($draft->id),
            fn () => Mainstay::drafts()->discard($draft->id),
            fn () => Mainstay::revisions()->of(Bulletin::class, $entry->id),
            fn () => Mainstay::revisions()->restore($revision->id),
            fn () => Mainstay::restore(Bulletin::class, $trashed->id),
            fn () => Mainstay::destroy(Bulletin::class, $trashed->id),
            fn () => Mainstay::media()->restore($image),
            fn () => Mainstay::media()->destroy($image),
        ] as $call) {
            $this->assertThrows($call, AuthorizationException::class);
        }

        $this->assertSame([1, 1, 1], [DB::table('mainstay_drafts')->count(), DB::table('bulletin')->whereNotNull('deleted_at')->count(), DB::table('mainstay_media')->whereNotNull('deleted_at')->count()]);
    }
}
