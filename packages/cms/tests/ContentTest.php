<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Content\Entry;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Article;
use Mainstay\Tests\Fixtures\Listed;
use Mainstay\Tests\Fixtures\Memo;
use Mainstay\Tests\Fixtures\Page;
use Mainstay\Tests\Fixtures\Policies\ClosedPolicy;
use Mainstay\Tests\Fixtures\Policies\EditorPolicy;
use Mainstay\Tests\Fixtures\Policies\OpenPolicy;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\SiteSettings;
use Mainstay\Tests\Fixtures\Submission;
use Mainstay\Tests\Fixtures\Ticket;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/*
 | The phase 3 check: content written through the query layer and read back
 | through it, scoped to one site and one locale and out of the trash, with
 | what is internal kept from a reader and every write refused to a caller
 | who has not overridden access.
 */
class ContentTest extends DatabaseTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en', 'nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class, Page::class, Memo::class, Submission::class, Ticket::class, SiteSettings::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    /* A row written by hand, so the reads are checked apart from the writes
       that will fill them. */
    private function insert(array $row = [], array $locales = ['en' => []], int $site = 1): int
    {
        $id = DB::table('post')->insertGetId(['site_id' => $site, 'featured' => false, ...$row]);

        foreach ($locales as $locale => $values) {
            DB::table('post_locales')->insert([
                'parent_id' => $id, 'site_id' => $site, 'locale' => $locale, 'title' => 'Hello', 'slug' => 'hello', 'status' => 'live', ...$values,
            ]);
        }

        return $id;
    }

    private function ids(iterable $entries): array
    {
        return collect($entries)->pluck('id')->all();
    }

    #[Test]
    public function it_reads_an_entry_in_the_locale_asked_for(): void
    {
        $id = $this->insert(
            ['published_at' => '2026-09-10 06:30:00', 'featured' => true, 'reading_minutes' => 4, 'created_at' => '2026-09-01 10:00:00'],
            ['en' => ['title' => 'Hello'], 'nl' => ['title' => 'Hallo', 'slug' => 'hallo']],
        );
        DB::table('uris')->insert(['site_id' => 1, 'locale' => 'nl', 'uri' => '/blog/hallo', 'type' => 'post', 'entry_id' => $id]);

        $post = Mainstay::findById(Post::class, $id, locale: 'nl');

        $this->assertInstanceOf(Post::class, $post);
        $this->assertSame($id, $post->id);
        $this->assertSame('nl', $post->locale);
        $this->assertSame('Hallo', $post->title);
        $this->assertSame('/blog/hallo', $post->uri);
        $this->assertTrue($post->featured);
        $this->assertSame(4, $post->readingMinutes, 'A readonly property is initialized from outside its class.');
        $this->assertSame('2026-09-10 06:30:00 UTC', $post->publishedAt->format('Y-m-d H:i:s T'));
        $this->assertSame('2026-09-01 10:00:00 UTC', $post->createdAt->format('Y-m-d H:i:s T'));
        $this->assertNull($post->updatedAt);

        $english = Mainstay::findById(Post::class, $id, locale: 'en');

        $this->assertSame('Hello', $english->title);
        $this->assertNull($english->uri, 'The path is the one held in the locale read.');
    }

    #[Test]
    public function an_entry_with_no_row_in_a_locale_is_not_read_in_it(): void
    {
        $id = $this->insert();

        $this->assertNull(Mainstay::findById(Post::class, $id, locale: 'nl'));
        $this->assertSame([], $this->ids(Mainstay::find(Post::class, locale: 'nl')));
    }

    #[Test]
    public function it_reads_in_the_request_locale_and_refuses_one_that_is_not_content(): void
    {
        $this->insert([], ['en' => [], 'nl' => ['title' => 'Hallo']]);

        App::setLocale('nl');
        $this->assertSame(['Hallo'], Mainstay::find(Post::class)->pluck('title')->all());

        App::setLocale('de');
        $this->assertThrows(
            fn () => Mainstay::find(Post::class),
            InvalidArgumentException::class,
            'No locale was passed and App::getLocale() is "de", which is not a content locale: mainstay.locales holds en, nl.',
        );
        $this->assertThrows(fn () => Mainstay::find(Post::class, locale: 'fr'), InvalidArgumentException::class, 'The locale is "fr"');
    }

    #[Test]
    public function a_trashed_entry_and_another_sites_entry_are_not_read(): void
    {
        $kept = $this->insert();
        $this->insert(['deleted_at' => '2026-09-01 10:00:00']);
        $campaign = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);
        $this->insert([], ['en' => []], $campaign);

        $this->assertSame([$kept], $this->ids(Mainstay::find(Post::class)));
    }

    #[Test]
    public function an_internal_field_is_absent_unless_access_is_overridden(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        $post = Mainstay::findById(Post::class, $id);

        $this->assertFalse((new ReflectionProperty($post, 'editorNote'))->isInitialized($post), 'Absent, not the default the declaration gave.');
        $this->assertSame('Check the quote', Mainstay::findById(Post::class, $id, overrideAccess: true)->editorNote);
    }

    #[Test]
    public function an_internal_field_is_refused_as_a_filter_or_sort_in_the_words_an_unknown_one_is(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['editorNote' => 'Check the quote']), InvalidArgumentException::class, 'has no field called editorNote to filter or sort on.');
        $this->assertThrows(fn () => Mainstay::find(Post::class, sort: '-editorNote'), InvalidArgumentException::class, 'has no field called editorNote to filter or sort on.');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['nothing' => 1]), InvalidArgumentException::class, 'has no field called nothing to filter or sort on.');

        $this->assertSame([$id], $this->ids(Mainstay::find(Post::class, where: ['editorNote' => 'Check the quote'], overrideAccess: true)));
    }

    #[Test]
    public function reads_ask_about_mainstays_user_and_not_the_default_guards(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        /* A host's rule that its own signed-in members may do anything. Asked
           about the default guard, it would hand this visitor the note. */
        Gate::before(fn ($user) => true);
        $this->actingAs(new GenericUser(['id' => 1]));

        $post = Mainstay::findById(Post::class, $id);

        $this->assertFalse((new ReflectionProperty($post, 'editorNote'))->isInitialized($post));
    }

    #[Test]
    public function a_policy_found_by_name_does_not_answer_for_a_content_type(): void
    {
        /* Fixtures\Policies\PostPolicy is where Laravel guesses Post's policy
           lives, and it refuses anyone who is not signed in. */
        $id = $this->insert();

        $this->assertSame([$id], $this->ids(Mainstay::find(Post::class)));
        $this->assertArrayNotHasKey(Post::class, Gate::policies(), "The host's Gate is left as Laravel would have it.");
    }

    #[Test]
    public function a_policy_the_host_chose_for_a_base_class_answers_for_its_types(): void
    {
        $id = $this->insert(['editor_note' => 'Check the quote']);

        /* After a read, so nothing was settled by the first check. The guessed
           PostPolicy would refuse the read and EntryPolicy would hide the
           note, so only the host's choice gives both. */
        Mainstay::find(Post::class);
        Gate::policy(Entry::class, OpenPolicy::class);

        $this->assertSame('Check the quote', Mainstay::findById(Post::class, $id)->editorNote);
    }

    #[Test]
    public function a_policy_the_host_chose_for_an_interface_answers_for_its_types(): void
    {
        Mainstay::find(Memo::class);
        Gate::policy(Listed::class, ClosedPolicy::class);

        $this->assertThrows(fn () => Mainstay::find(Memo::class), AuthorizationException::class);
    }

    #[Test]
    public function a_write_asks_gate_about_the_entry_it_loaded_owner_and_all(): void
    {
        $id = $this->insert(['owner_id' => 7]);

        $asked = null;
        Gate::before(function (?object $user, string $ability, array $arguments) use (&$asked) {
            $asked = $ability === 'update' ? $arguments[0] : $asked;
        });

        $this->assertThrows(fn () => Mainstay::update(Post::class, $id, ['featured' => true]), AuthorizationException::class);
        $this->assertInstanceOf(Post::class, $asked);
        $this->assertSame([$id, 7], [$asked->id, $asked->ownerId]);
        $this->assertSame(7, Mainstay::findById(Post::class, $id)->ownerId);
    }

    #[Test]
    public function a_policy_the_host_chose_answers_for_a_content_type(): void
    {
        $this->insert();

        /* Chosen with #[UsePolicy] on the class. */
        $this->assertThrows(fn () => Mainstay::find(Page::class), AuthorizationException::class);

        /* Chosen with Gate::policy() on the type, after a read. */
        Mainstay::find(Post::class);
        Gate::policy(Post::class, ClosedPolicy::class);
        $this->assertThrows(fn () => Mainstay::find(Post::class), AuthorizationException::class);
    }

    #[Test]
    public function where_compares_a_date_as_the_utc_column_holds_it(): void
    {
        $early = $this->insert(['published_at' => '2026-09-10 06:30:00']);
        $this->insert(['published_at' => '2026-09-10 07:30:00']);

        /* 07:00 UTC. Formatted in its own zone it would read 09:00 and match
           both rows. */
        $cut = CarbonImmutable::parse('2026-09-10 09:00:00', 'Europe/Amsterdam');

        $this->assertSame([$early], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => ['<=' => $cut]])));
    }

    #[Test]
    public function where_takes_equality_null_in_and_not_in_across_both_tables(): void
    {
        $draft = $this->insert(['featured' => true], ['en' => ['status' => 'draft', 'slug' => 'draft']]);
        $live = $this->insert(['published_at' => '2026-09-10 06:30:00'], ['en' => ['slug' => 'live']]);

        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['featured' => true])));
        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => null])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['publishedAt' => ['!=' => null]])));
        $this->assertSame([$draft], $this->ids(Mainstay::find(Post::class, where: ['status' => ['in' => ['draft']]])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['status' => ['not_in' => ['draft']]])));
        $this->assertSame([$live], $this->ids(Mainstay::find(Post::class, where: ['id' => ['!=' => $draft], 'slug' => 'live'])));
        $this->assertSame([$draft, $live], $this->ids(Mainstay::find(Post::class, where: ['createdAt' => null])));
    }

    #[Test]
    public function a_limit_or_page_of_nothing_is_refused(): void
    {
        $this->assertThrows(fn () => Mainstay::find(Post::class, limit: 0), InvalidArgumentException::class, 'A limit reads at least one entry; 0 is not one.');
        $this->assertThrows(fn () => Mainstay::paginate(Post::class, perPage: 0), InvalidArgumentException::class, 'A page holds at least one entry; 0 is not one.');
    }

    #[Test]
    public function sort_refuses_a_key_named_twice(): void
    {
        $this->assertThrows(fn () => Mainstay::find(Post::class, sort: ['publishedAt', '-publishedAt']), InvalidArgumentException::class, 'The sort names publishedAt more than once.');
    }

    #[Test]
    public function where_refuses_a_bare_list_and_an_operator_it_does_not_take(): void
    {
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['status' => ['draft', 'live']]), InvalidArgumentException::class, "write ['in' => [...]]");
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['title' => ['like' => '%Hel%']]), InvalidArgumentException::class, 'like is not an operator a where takes');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['publishedAt' => ['<' => null]]), InvalidArgumentException::class, 'Only = and != take null.');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['title' => ['=' => ['B', 'A']]]), InvalidArgumentException::class, 'compares = with a list');
        $this->assertThrows(fn () => Mainstay::find(Post::class, where: ['title' => ['in' => [['B']]]]), InvalidArgumentException::class, 'compares in with a list');
    }

    #[Test]
    public function every_sort_ends_on_the_id_and_names_it_once(): void
    {
        /* Asserted on the statement, because no driver here returns tied rows
           out of id order to be caught doing it -- which is what the
           tie-breaker guards against where one does. */
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $orderBy = function (callable $read) use (&$statements): string {
            $statements = [];
            $read();

            return strtolower(substr(end($statements), strripos(end($statements), ' order by ')));
        };
        $ids = fn (string $orderBy) => preg_match_all('/[`"\[]id[`"\]]/', $orderBy);

        $byDate = $orderBy(fn () => Mainstay::find(Post::class, sort: '-publishedAt'));
        $this->assertSame(1, $ids($byDate));
        $this->assertMatchesRegularExpression('/[`"\[]id[`"\]] asc$/', $byDate);

        $this->assertSame(1, $ids($orderBy(fn () => Mainstay::find(Post::class, sort: '-id'))), 'SQL Server refuses a column named twice.');
        $this->assertSame(1, $ids($orderBy(fn () => Mainstay::find(Post::class, sort: ['-publishedAt', 'id']))));
    }

    #[Test]
    public function sort_puts_nulls_last_either_way_and_pages_in_that_order(): void
    {
        $undated = $this->insert();
        $early = $this->insert(['published_at' => '2026-01-01 00:00:00']);
        $late = $this->insert(['published_at' => '2026-06-01 00:00:00']);
        $tie = $this->insert(['published_at' => '2026-06-01 00:00:00']);

        $this->assertSame([$late, $tie, $early, $undated], $this->ids(Mainstay::find(Post::class, sort: '-publishedAt')));
        $this->assertSame([$early, $late, $tie, $undated], $this->ids(Mainstay::find(Post::class, sort: ['publishedAt'])));
        $this->assertSame([$late, $tie], $this->ids(Mainstay::find(Post::class, sort: '-publishedAt', limit: 2)));

        $page = Mainstay::paginate(Post::class, sort: '-publishedAt', perPage: 2, page: 2);

        $this->assertSame(4, $page->total());
        $this->assertSame([$early, $undated], $this->ids($page->items()));
        $this->assertInstanceOf(Post::class, $page->items()[0]);
    }

    private function writePost(array $data = [], string $locale = 'en'): Post
    {
        return Mainstay::create(Post::class, ['title' => 'Hello', 'slug' => 'hello', 'status' => 'live', ...$data], locale: $locale, overrideAccess: true);
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

    #[Test]
    public function it_creates_an_entry_and_reads_it_back(): void
    {
        $post = $this->writePost([
            'publishedAt' => CarbonImmutable::parse('2026-09-10 08:30:00', 'Europe/Amsterdam'),
            'featured' => true,
            'readingMinutes' => 4,
            'editorNote' => 'Check the quote',
        ]);

        $this->assertSame('en', $post->locale);
        $this->assertSame('/blog/hello', $post->uri);
        $this->assertSame('2026-09-10 06:30:00 UTC', $post->publishedAt->format('Y-m-d H:i:s T'));
        $this->assertTrue($post->featured);
        $this->assertSame(4, $post->readingMinutes);
        $this->assertSame('Check the quote', $post->editorNote, 'Read back with the access it was written with.');
        $this->assertNotNull($post->createdAt);
        $this->assertEquals($post->createdAt, $post->updatedAt);

        $read = Mainstay::findById(Post::class, $post->id);

        $this->assertSame('Hello', $read->title);
        $this->assertFalse((new ReflectionProperty($read, 'editorNote'))->isInitialized($read));
        $this->assertNull(DB::table('post')->value('owner_id'));
        $this->assertSame([['en', '/blog/hello']], DB::table('uris')->get()->map(fn ($row) => [$row->locale, $row->uri])->all());
    }

    #[Test]
    public function a_field_left_off_a_create_holds_what_its_type_stores_for_nothing(): void
    {
        $post = $this->writePost();

        $this->assertFalse($post->featured);
        $this->assertNull($post->publishedAt);
        $this->assertNull($post->readingMinutes);
    }

    #[Test]
    public function a_write_without_the_override_is_refused_and_writes_nothing(): void
    {
        $id = $this->writePost()->id;

        foreach ([
            fn () => Mainstay::create(Post::class, ['title' => 'Other', 'slug' => 'other', 'status' => 'live']),
            fn () => Mainstay::update(Post::class, $id, ['title' => 'Changed']),
            fn () => Mainstay::delete(Post::class, $id),
        ] as $write) {
            $this->assertThrows($write, AuthorizationException::class, 'Code that writes on its own authority passes overrideAccess: true.');
        }

        $this->assertSame(['Hello'], Mainstay::find(Post::class)->pluck('title')->all());
    }

    #[Test]
    public function a_caller_that_may_write_and_not_read_is_handed_what_it_wrote(): void
    {
        $sent = Mainstay::create(Submission::class, ['name' => 'Ann'], locale: 'en');

        $this->assertSame('Ann', $sent->name);
        $this->assertFalse((new ReflectionProperty($sent, 'message'))->isInitialized($sent), 'Not written, so not handed back.');
        $this->assertFalse((new ReflectionProperty($sent, 'state'))->isInitialized($sent), 'Internal, to a caller who may not see it.');
        $this->assertSame(['Ann', 'new'], [DB::table('submission')->value('name'), DB::table('submission')->value('state')]);
        $this->assertThrows(fn () => Mainstay::find(Submission::class), AuthorizationException::class);

        Mainstay::update(Submission::class, $sent->id, ['message' => 'Hello'], locale: 'en', overrideAccess: true);
        $amended = Mainstay::update(Submission::class, $sent->id, ['message' => 'Hello again'], locale: 'en');

        $this->assertSame('Hello again', $amended->message);
        $this->assertFalse((new ReflectionProperty($amended, 'name'))->isInitialized($amended), 'Stored, but not written by this call.');
    }

    #[Test]
    public function a_field_the_writer_may_not_see_and_has_no_default_refuses_the_write_unnamed(): void
    {
        $this->assertThrows(
            fn () => Mainstay::create(Ticket::class, ['name' => 'Ann'], locale: 'en'),
            AuthorizationException::class,
            'Writing a Ticket needs a field this caller may not see. Give it a default in the declaration, or write with access to it.',
        );
        $this->assertSame(0, DB::table('ticket')->count());
    }

    #[Test]
    public function a_writer_that_may_not_see_an_internal_field_cannot_write_it(): void
    {
        Gate::policy(Post::class, EditorPolicy::class);
        Gate::policy(Memo::class, EditorPolicy::class);
        $post = $this->writePost(['editorNote' => 'Check the quote']);

        $this->assertThrows(
            fn () => Mainstay::update(Post::class, $post->id, ['editorNote' => 'Overwritten'], locale: 'en'),
            InvalidArgumentException::class,
            'has no field called editorNote to write.',
        );
        $this->assertSame('Changed', Mainstay::update(Post::class, $post->id, ['title' => 'Changed'], locale: 'en')->title);
        $this->assertSame('Check the quote', DB::table('post')->value('editor_note'));

        /* A stored internal value that breaks its rule is not reported to a
           writer who cannot see it -- and is, to one who can. */
        $memo = Mainstay::create(Memo::class, ['note' => 'Call back', 'title' => 'Printer'], locale: 'en', overrideAccess: true);
        DB::table('memo')->update(['note' => '']);

        $this->assertSame('Plotter', Mainstay::update(Memo::class, $memo->id, ['title' => 'Plotter'], locale: 'en')->title);
        $this->assertSame(['note'], array_keys($this->refusal(fn () => Mainstay::update(Memo::class, $memo->id, ['title' => 'Scanner'], locale: 'en', overrideAccess: true))));
    }

    #[Test]
    public function it_refuses_what_the_declaration_refuses(): void
    {
        $this->assertSame(['title'], array_keys($this->refusal(fn () => $this->writePost(['title' => null]))));
        $this->assertSame(['status'], array_keys($this->refusal(fn () => $this->writePost(['status' => 'published']))));
        $this->assertStringContainsString('lowercase letters, digits and single hyphens', $this->refusal(fn () => $this->writePost(['slug' => 'Hello']))['slug'][0]);
        $this->assertArrayHasKey('slug', $this->refusal(fn () => $this->writePost(['slug' => 'hello/world'])));
        $this->assertThrows(fn () => $this->writePost(['titel' => 'Hello']), InvalidArgumentException::class, 'has no field called titel to write.');

        $this->assertSame(0, DB::table('post')->count());
    }

    #[Test]
    public function a_path_another_entry_holds_is_refused_on_the_slug_and_nothing_is_written(): void
    {
        $this->writePost();

        $this->assertSame(
            ['slug' => ['The path /blog/hello is already taken in en.']],
            $this->refusal(fn () => $this->writePost(['title' => 'Another'])),
        );
        $this->assertSame([1, 1, 1], [DB::table('post')->count(), DB::table('post_locales')->count(), DB::table('uris')->count()]);
    }

    #[Test]
    public function an_update_moving_onto_a_taken_path_is_refused_and_writes_nothing(): void
    {
        $this->writePost();
        $other = $this->writePost(['title' => 'Other', 'slug' => 'other'])->id;

        $this->assertSame(
            ['slug' => ['The path /blog/hello is already taken in en.']],
            $this->refusal(fn () => Mainstay::update(Post::class, $other, ['slug' => 'hello'], locale: 'en', overrideAccess: true)),
        );
        $this->assertSame('other', Mainstay::findById(Post::class, $other)->slug);
        $this->assertSame(['/blog/hello', '/blog/other'], DB::table('uris')->orderBy('id')->pluck('uri')->all());
    }

    #[Test]
    public function a_save_clears_a_second_path_row_left_from_outside(): void
    {
        $id = $this->writePost()->id;
        DB::table('uris')->insert(['site_id' => 1, 'locale' => 'en', 'uri' => '/blog/stray', 'type' => 'post', 'entry_id' => $id]);

        Mainstay::update(Post::class, $id, ['featured' => true], locale: 'en', overrideAccess: true);

        $this->assertSame(1, DB::table('uris')->count());
        $this->assertSame([$id], $this->ids(Mainstay::find(Post::class)));
    }

    #[Test]
    public function a_seeders_own_transaction_survives_a_path_it_was_refused(): void
    {
        /* The refused insert is rolled back to a savepoint, which is what
           keeps Postgres, having abandoned the statement, accepting the next
           one. */
        DB::transaction(function () {
            $this->writePost();
            $this->refusal(fn () => $this->writePost(['title' => 'Another']));
            $this->writePost(['slug' => 'another']);
        });

        $this->assertSame(['hello', 'another'], Mainstay::find(Post::class)->pluck('slug')->all());
    }

    #[Test]
    public function a_path_longer_than_its_column_is_refused_on_the_fields_that_build_it(): void
    {
        $errors = $this->refusal(fn () => $this->writePost(['slug' => str_repeat('a', 250)]));

        $this->assertStringContainsString('is longer than the 255 characters a path can be', $errors['slug'][0]);
        $this->assertSame([0, 0, 0], [DB::table('post')->count(), DB::table('post_locales')->count(), DB::table('uris')->count()]);
    }

    #[Test]
    public function an_update_adds_a_translation_and_asks_for_its_localized_fields(): void
    {
        $id = $this->writePost()->id;

        /* status is a required localized select, which has no empty value
           to read a missing row as. */
        $this->assertEqualsCanonicalizing(['slug', 'status'], array_keys($this->refusal(
            fn () => Mainstay::update(Post::class, $id, ['title' => 'Hallo'], locale: 'nl', overrideAccess: true),
        )));

        $dutch = Mainstay::update(Post::class, $id, ['title' => 'Hallo', 'slug' => 'hallo', 'status' => 'draft'], locale: 'nl', overrideAccess: true);

        $this->assertSame(['Hallo', 'draft', '/nieuws/hallo'], [$dutch->title, $dutch->status, $dutch->uri]);

        $english = Mainstay::findById(Post::class, $id, locale: 'en');

        $this->assertSame(['Hello', 'live', '/blog/hello'], [$english->title, $english->status, $english->uri]);
    }

    #[Test]
    public function only_a_locale_written_out_adds_a_translation(): void
    {
        $post = $this->writePost();
        $memo = Mainstay::create(Memo::class, ['note' => 'Call back', 'title' => 'Printer'], locale: 'en', overrideAccess: true);

        /* A Dutch visitor's request, and an edit that names no locale. */
        App::setLocale('nl');

        $this->assertThrows(
            fn () => Mainstay::update(Post::class, $post->id, ['featured' => true], overrideAccess: true),
            RecordNotFoundException::class,
            "has no nl translation to update. Pass locale: 'nl' to add one.",
        );
        $this->assertThrows(fn () => Mainstay::update(Memo::class, $memo->id, ['title' => 'Plotter'], overrideAccess: true), RecordNotFoundException::class);
        $this->assertSame(0, DB::table('memo_locales')->where('locale', 'nl')->count());
        $this->assertFalse((bool) DB::table('post')->value('featured'));

        $this->assertSame('Plotter', Mainstay::update(Memo::class, $memo->id, ['title' => 'Plotter'], locale: 'nl', overrideAccess: true)->title);

        App::setLocale('en');
        $this->assertTrue(Mainstay::update(Post::class, $post->id, ['featured' => true], overrideAccess: true)->featured);
    }

    #[Test]
    public function an_update_changes_what_it_is_given_and_keeps_every_locales_path(): void
    {
        $id = $this->writePost()->id;
        Mainstay::update(Post::class, $id, ['title' => 'Hallo', 'slug' => 'hallo', 'status' => 'live'], locale: 'nl', overrideAccess: true);

        $moved = Mainstay::update(Post::class, $id, ['slug' => 'hello-again'], locale: 'en', overrideAccess: true);

        $this->assertSame(['Hello', '/blog/hello-again'], [$moved->title, $moved->uri]);
        $this->assertSame('/nieuws/hallo', Mainstay::findById(Post::class, $id, locale: 'nl')->uri, 'Rebuilt, not dropped, by a write in another locale.');

        Mainstay::update(Post::class, $id, ['featured' => true], locale: 'en', overrideAccess: true);

        $this->assertTrue(Mainstay::findById(Post::class, $id, locale: 'nl')->featured, 'A shared field is one value in every locale.');
    }

    #[Test]
    public function an_update_writes_only_the_columns_it_was_given(): void
    {
        $id = $this->writePost(['readingMinutes' => 4])->id;

        /* Another save, landing once this one has read the locale rows and
           before it writes anything. */
        $raced = false;
        DB::listen(function ($query) use ($id, &$raced) {
            $sql = strtolower($query->sql);

            if (! $raced && str_starts_with($sql, 'select') && str_contains($sql, 'post_locales')) {
                $raced = true;
                DB::table('post')->where('id', $id)->update(['reading_minutes' => 9]);
                DB::table('post_locales')->where('parent_id', $id)->update(['slug' => 'raced']);
            }
        });

        $post = Mainstay::update(Post::class, $id, ['featured' => true, 'title' => 'Changed'], locale: 'en', overrideAccess: true);

        $this->assertTrue($raced);

        $this->assertSame([true, 'Changed', 9, 'raced'], [$post->featured, $post->title, $post->readingMinutes, $post->slug]);
    }

    #[Test]
    public function an_update_a_delete_overtook_writes_nothing_and_gives_no_path_back(): void
    {
        $id = $this->writePost()->id;

        /* Another request trashing the entry once this save has loaded it. */
        $deleted = false;
        DB::listen(function ($query) use ($id, &$deleted) {
            $sql = strtolower($query->sql);

            if (! $deleted && str_starts_with($sql, 'select') && str_contains($sql, 'post_locales')) {
                $deleted = true;
                DB::table('post')->where('id', $id)->update(['deleted_at' => '2026-09-23 00:00:00']);
                DB::table('uris')->where('entry_id', $id)->delete();
            }
        });

        $this->assertThrows(fn () => Mainstay::update(Post::class, $id, ['title' => 'Changed'], locale: 'en', overrideAccess: true), RecordNotFoundException::class);
        $this->assertTrue($deleted);
        $this->assertSame('Hello', DB::table('post_locales')->value('title'));
        $this->assertSame(0, DB::table('uris')->count());
    }

    #[Test]
    public function an_update_inside_a_callers_transaction_sees_a_delete_committed_since(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite in memory is one connection, so nothing can commit beside it.');
        }

        config()->set('database.connections.beside', config('database.connections.testing'));
        $id = $this->writePost()->id;

        /* The caller's transaction reads first, which is where MySQL takes the
           snapshot every plain read in it then answers from. */
        DB::beginTransaction();
        DB::table('post')->count();

        DB::connection('beside')->table('post')->where('id', $id)->update(['deleted_at' => '2026-09-24 00:00:00']);
        DB::connection('beside')->table('uris')->where('entry_id', $id)->delete();

        /* Committed, not rolled back, so whatever the update wrote stays to
           be seen. */
        try {
            $this->assertThrows(fn () => Mainstay::update(Post::class, $id, ['title' => 'Changed'], locale: 'en', overrideAccess: true), RecordNotFoundException::class);
        } finally {
            DB::commit();
        }

        $this->assertSame('Hello', DB::table('post_locales')->value('title'));
        $this->assertSame(0, DB::table('uris')->count());
    }

    #[Test]
    public function a_delete_inside_a_callers_transaction_takes_a_path_added_since(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite in memory is one connection, so nothing can commit beside it.');
        }

        config()->set('database.connections.beside', config('database.connections.testing'));
        $id = $this->writePost()->id;

        DB::beginTransaction();
        DB::table('post')->count();

        DB::connection('beside')->table('post_locales')->insert(['parent_id' => $id, 'site_id' => 1, 'locale' => 'nl', 'title' => 'Hallo', 'slug' => 'hallo', 'status' => 'live']);
        DB::connection('beside')->table('uris')->insert(['site_id' => 1, 'locale' => 'nl', 'uri' => '/nieuws/hallo', 'type' => 'post', 'entry_id' => $id]);

        Mainstay::delete(Post::class, $id, overrideAccess: true);
        DB::commit();

        $this->assertSame(0, DB::table('uris')->count(), 'No path outlives the trash.');
    }

    #[Test]
    public function an_update_leaves_a_path_it_did_not_move_alone(): void
    {
        $id = $this->writePost()->id;
        $row = DB::table('uris')->first();

        Mainstay::update(Post::class, $id, ['featured' => true], locale: 'en', overrideAccess: true);

        $this->assertEquals($row, DB::table('uris')->first(), 'Not deleted and put back.');

        Mainstay::update(Post::class, $id, ['slug' => 'moved'], locale: 'en', overrideAccess: true);

        $this->assertSame([$row->id, '/blog/moved'], [DB::table('uris')->value('id'), DB::table('uris')->value('uri')]);
        $this->assertSame(1, DB::table('uris')->count());
    }

    #[Test]
    public function a_shared_field_in_the_pattern_moves_every_locales_path(): void
    {
        $id = Mainstay::create(Page::class, ['section' => 'about', 'slug' => 'team'], locale: 'en', overrideAccess: true)->id;
        Mainstay::update(Page::class, $id, ['slug' => 'ploeg'], locale: 'nl', overrideAccess: true);

        Mainstay::update(Page::class, $id, ['section' => 'work'], locale: 'en', overrideAccess: true);

        $this->assertEqualsCanonicalizing(['/work/team', '/work/ploeg'], DB::table('uris')->pluck('uri')->all());
    }

    #[Test]
    public function a_type_with_nothing_localized_is_written_in_the_locale_it_is_given(): void
    {
        $memo = Mainstay::create(Memo::class, ['note' => 'Call back', 'title' => 'Printer'], locale: 'en', overrideAccess: true);
        $changed = Mainstay::update(Memo::class, $memo->id, ['title' => 'Plotter'], locale: 'en', overrideAccess: true);

        $this->assertSame('Plotter', $changed->title);
        $this->assertNull($changed->uri);
        $this->assertSame('Call back', $changed->note);

        $read = Mainstay::findById(Memo::class, $memo->id, locale: 'en');
        $this->assertFalse((new ReflectionProperty($read, 'note'))->isInitialized($read), 'A base field widened by the child is still internal.');
        $this->assertNull(Mainstay::findById(Memo::class, $memo->id, locale: 'nl'), 'Only in the locales it was written in.');
    }

    #[Test]
    public function delete_trashes_every_locale_and_takes_the_paths_with_it(): void
    {
        $id = $this->writePost()->id;
        Mainstay::update(Post::class, $id, ['title' => 'Hallo', 'slug' => 'hallo', 'status' => 'live'], locale: 'nl', overrideAccess: true);

        Mainstay::delete(Post::class, $id, overrideAccess: true);

        $this->assertNull(Mainstay::findById(Post::class, $id, locale: 'en'));
        $this->assertNull(Mainstay::findById(Post::class, $id, locale: 'nl'));
        $this->assertNotNull(DB::table('post')->value('deleted_at'));
        $this->assertSame(DB::table('post')->value('deleted_at'), DB::table('post')->value('updated_at'));
        $this->assertSame(2, DB::table('post_locales')->count(), 'Trashed, not removed.');
        $this->assertSame(0, DB::table('uris')->count());

        $this->assertThrows(fn () => Mainstay::delete(Post::class, $id, overrideAccess: true), RecordNotFoundException::class);
        $this->assertThrows(fn () => Mainstay::update(Post::class, $id, ['title' => 'Back'], overrideAccess: true), RecordNotFoundException::class);
    }

    #[Test]
    public function an_entry_on_another_site_cannot_be_written_from_this_one(): void
    {
        $campaign = DB::table('sites')->insertGetId(['handle' => 'campaign', 'name' => 'Campaign', 'hostname' => 'campaign.test']);
        $id = $this->insert([], ['en' => []], $campaign);

        $this->assertThrows(fn () => Mainstay::update(Post::class, $id, ['title' => 'Taken'], overrideAccess: true), RecordNotFoundException::class);
    }

    #[Test]
    public function a_route_map_has_to_name_every_content_locale(): void
    {
        config()->set('mainstay.locales', ['en', 'nl', 'de']);

        $this->assertThrows(
            fn () => $this->writePost(),
            InvalidArgumentException::class,
            '#[Route] has patterns for en, nl, and mainstay.locales holds en, nl, de.',
        );
    }

    #[Test]
    public function it_reads_registered_entries_and_nothing_else(): void
    {
        $this->assertThrows(fn () => Mainstay::find(SiteSettings::class), InvalidArgumentException::class, 'is not an entry');
        $this->assertThrows(fn () => Mainstay::find(Article::class), InvalidArgumentException::class, 'is not a registered content type');
    }
}
