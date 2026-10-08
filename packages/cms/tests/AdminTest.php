<?php

namespace Mainstay\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Content\Media;
use Mainstay\Facades\Mainstay;
use Mainstay\Http\Form;
use Mainstay\Tests\Fixtures\Accented\Article as Accented;
use Mainstay\Tests\Fixtures\Broken\Login;
use Mainstay\Tests\Fixtures\Guide;
use Mainstay\Tests\Fixtures\Linked\Genre;
use Mainstay\Tests\Fixtures\Linked\Person;
use Mainstay\Tests\Fixtures\Linked\Story;
use Mainstay\Tests\Fixtures\Pictured;
use Mainstay\Tests\Fixtures\Policies\ClosedPolicy;
use Mainstay\Tests\Fixtures\Post;
use Mainstay\Tests\Fixtures\Signed\Banner;
use Mainstay\Tests\Fixtures\Signed\Flyer;
use Mainstay\Tests\Fixtures\Signed\Note;
use Mainstay\Tests\Fixtures\Signed\Topic;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 10 check: the admin's frame, lists and forms, over the query
 | layer -- a round trip in two languages, saving and publishing, two editors
 | on one draft, the list and the trash, who is offered what, terms, globals,
 | the front page, rich text and the raw blocks. The layer underneath runs on
 | every driver in its own suites; this drives it through HTTP.
 */
class AdminTest extends DatabaseTestCase
{
    private int $made = 0;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mainstay.locales', ['en' => '/', 'nl' => '/nl']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Post::class, Note::class, Flyer::class, Topic::class, Banner::class, Guide::class, Person::class, Story::class, Genre::class, Pictured::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->signIn($this->user(role: 'administrator'));
    }

    /* Another account, in a session of its own: the last one's holds the
       password hash AuthenticateSession checks, and would sign this one out. */
    private function signIn(User $user): void
    {
        $this->flushSession();
        $this->actingAs($user, 'mainstay');
    }

    private function user(array $capabilities = [], ?string $role = null): User
    {
        $n = ++$this->made;
        $role = $role === null
            ? Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities])
            : Role::query()->where('name', $role)->sole();

        return User::query()->create(['name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => 'correct horse battery', 'role_id' => $role->id]);
    }

    /* What a form drew as each field's fingerprint, and when its draft was
       saved, as a browser would post them back. */
    private function drawn(TestResponse $response): array
    {
        preg_match_all('/name="_seen\[(\w+)\]" value="(\w+)"/', $response->getContent(), $seen, PREG_SET_ORDER);
        preg_match('/name="_draft_at" value="([^"]*)"/', $response->getContent(), $at);

        return ['_seen' => array_column($seen, 2, 1), '_draft_at' => html_entity_decode($at[1] ?? '')];
    }

    private function values(array $fields = []): array
    {
        return ['title' => 'Hello', 'slug' => 'hello', 'status' => 'live', 'publishedAt' => '2026-10-07T14:30:00+02:00', 'featured' => '0', 'readingMinutes' => '4', 'template' => 'post', ...$fields];
    }

    #[Test]
    public function an_article_written_in_the_admin_reads_back_through_the_layer_in_both_languages(): void
    {
        $saved = $this->post(route('mainstay.entries.store', 'post'), $this->values(['featured' => '1']));
        $draft = Mainstay::drafts()->all(Post::class, locale: 'en')->sole();
        $saved->assertRedirect(route('mainstay.drafts.edit', ['post', $draft->id]));
        $this->assertNull(Mainstay::findByUri('/blog/hello', locale: 'en'), 'Saved as a draft, which the site does not show.');

        $form = $this->get(route('mainstay.drafts.edit', ['post', $draft->id]))->assertOk()->assertSee('value="Hello"', escape: false);
        $published = $this->post(route('mainstay.drafts.update', ['post', $draft->id]), [...$this->values(['featured' => '1']), ...$this->drawn($form), 'intent' => 'publish']);
        $post = Mainstay::findByUri('/blog/hello', locale: 'en');
        $published->assertRedirect(route('mainstay.entries.edit', ['post', $post->id]));

        $this->assertSame(['Hello', 'hello', 'live', true, 4], [$post->title, $post->slug, $post->status, $post->featured, $post->readingMinutes]);
        $this->assertEquals(CarbonImmutable::parse('2026-10-07 12:30:00', 'UTC'), $post->publishedAt, 'Posted with an offset, stored in UTC.');

        /* The Dutch, from the toggle: the shared fields come filled in. */
        $dutch = $this->get(route('mainstay.entries.edit', ['post', $post->id, 'locale' => 'nl']))->assertOk()->assertSee('There is no NL version yet', escape: false);
        $this->post(route('mainstay.entries.update', ['post', $post->id, 'locale' => 'nl']), [
            ...$this->values(['title' => 'Hallo', 'slug' => 'hallo', 'featured' => '1']), ...$this->drawn($dutch), 'intent' => 'publish',
        ])->assertRedirect(route('mainstay.entries.edit', ['post', $post->id, 'locale' => 'nl']))->assertSessionHasNoErrors();

        $nl = Mainstay::findByUri('/nieuws/hallo', locale: 'nl');
        $this->assertSame([$post->id, 'Hallo', true], [$nl->id, $nl->title, $nl->featured]);
        $this->assertSame('Hello', Mainstay::findById(Post::class, $post->id, locale: 'en')->title);
    }

    #[Test]
    public function save_draft_keeps_the_site_as_it_was_and_publish_puts_it_live(): void
    {
        $id = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true)->id;
        $form = $this->get(route('mainstay.entries.edit', ['post', $id]))->assertOk()->assertDontSee('data-slug-from', escape: false);

        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed']), ...$this->drawn($form)])->assertSessionHasNoErrors();
        $this->assertSame('Hello', Mainstay::findById(Post::class, $id, locale: 'en')->title);
        $this->assertSame(['title'], Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true)->locales['en'], 'Only what changed is drafted.');

        /* A publish the layer refuses keeps the draft, and says why. */
        $form = $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('Unpublished changes');
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed', 'slug' => '']), ...$this->drawn($form), 'intent' => 'publish'])
            ->assertSessionHasErrors('slug');
        $this->assertSame('Changed', Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true)->entry->title);

        $form = $this->get(route('mainstay.entries.edit', ['post', $id]));
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed']), ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $this->assertSame(['Changed', 'hello'], [Mainstay::findById(Post::class, $id, locale: 'en')->title, Mainstay::findById(Post::class, $id, locale: 'en')->slug]);
        $this->assertNull(Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true));

        /* And Discard draft throws one away. */
        $form = $this->get(route('mainstay.entries.edit', ['post', $id]));
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Again']), ...$this->drawn($form)]);
        $form = $this->get(route('mainstay.entries.edit', ['post', $id]));
        $draft = Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true);
        $this->post(route('mainstay.drafts.discard', ['post', $draft->id]), $this->drawn($form))->assertSessionHasNoErrors();
        $this->assertNull(Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true));
        $this->assertSame('Changed', Mainstay::findById(Post::class, $id, locale: 'en')->title);
    }

    #[Test]
    public function two_forms_on_one_draft_each_keep_their_change(): void
    {
        $id = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true)->id;
        $first = $this->drawn($this->get(route('mainstay.entries.edit', ['post', $id])));
        $second = $this->drawn($this->get(route('mainstay.entries.edit', ['post', $id])));

        /* The first changes the title; the second, drawn before that, changes
           the status and posts the old title and the same moment written in
           another zone. */
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed']), ...$first])->assertSessionHasNoErrors();
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['status' => 'draft', 'publishedAt' => '2026-10-07T07:30:00-05:00']), ...$second])->assertSessionHasNoErrors();

        $draft = Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true);
        $this->assertSame(['Changed', 'draft'], [$draft->entry->title, $draft->entry->status]);
        $this->assertSame([], $draft->fields, 'The moment was unchanged, so nothing shared is drafted.');

        /* The moment changed by one form and left alone by another drawn
           before: the later save does not put the old one back. */
        $third = $this->drawn($this->get(route('mainstay.entries.edit', ['post', $id])));
        $fourth = $this->drawn($this->get(route('mainstay.entries.edit', ['post', $id])));
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed', 'status' => 'draft', 'publishedAt' => '2026-12-01T10:00:00+00:00']), ...$third]);
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => 'Changed', 'status' => 'draft', 'featured' => '1']), ...$fourth]);
        $draft = Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true);
        $this->assertEquals([CarbonImmutable::parse('2026-12-01 10:00:00', 'UTC'), true], [$draft->entry->publishedAt, $draft->entry->featured]);

        /* A publish from a form drawn before the last save is refused, the
           save itself kept. */
        $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['featured' => '1']), ...$first, 'intent' => 'publish'])
            ->assertSessionHasErrors('draft');
        $this->assertSame('Hello', Mainstay::findById(Post::class, $id, locale: 'en')->title);
        $this->assertTrue(Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true)->entry->featured);

        /* And so is a discard. */
        $draft = Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true);
        $this->post(route('mainstay.drafts.discard', ['post', $draft->id]), $first)->assertSessionHasErrors('draft');
        $this->assertNotNull(Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true));
    }

    #[Test]
    public function what_code_wrote_posts_back_unchanged_from_an_untouched_form(): void
    {
        $id = Mainstay::create(Post::class, [...$this->values(['title' => ' Hello ', 'publishedAt' => '2026-10-07 12:30:45']), 'editorNote' => "One\nTwo"], locale: 'en', overrideAccess: true)->id;
        $form = $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('value="2026-10-07T12:30:45"', escape: false);

        /* What a browser sends back: the title trimmed by the host's
           middleware, the textarea's lines ending in CRLF, the moment in
           another zone, to the second. */
        $this->post(route('mainstay.entries.update', ['post', $id]), [
            ...$this->values(['title' => 'Hello', 'publishedAt' => '2026-10-07T14:30:45+02:00']), 'editorNote' => "One\r\nTwo", ...$this->drawn($form),
        ])->assertSessionHas('status', 'Nothing to save: this is what is live.');
        $this->assertNull(Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true));

        /* A field a form does not post is left as it is. */
        $this->post(route('mainstay.entries.update', ['post', $id]), [...array_diff_key($this->values(['title' => 'Changed']), ['readingMinutes' => 0]), ...$this->drawn($form), 'intent' => 'publish']);
        $this->assertSame(['Changed', 4], [Mainstay::findById(Post::class, $id, locale: 'en')->title, Mainstay::findById(Post::class, $id, locale: 'en')->readingMinutes]);
    }

    #[Test]
    public function the_form_draws_each_scalar_as_its_control(): void
    {
        $form = $this->get(route('mainstay.entries.create', 'post'))->assertOk();

        $form->assertSee('<input type="hidden" name="featured" value="0">', escape: false)
            ->assertSee('maxlength="255"', escape: false)
            ->assertSee('type="datetime-local"', escape: false)
            ->assertSee('step="1"', escape: false)
            ->assertSee('name="template"', escape: false)
            ->assertSee('data-slug-from="title"', escape: false)
            ->assertSeeInOrder(['name="status"', '<option value="">—</option>'], escape: false);
    }

    #[Test]
    public function the_list_shows_what_is_live_drafted_and_new_and_pages_through_it(): void
    {
        $live = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true);
        $changed = Mainstay::create(Post::class, $this->values(['title' => 'Second', 'slug' => 'second']), locale: 'en', overrideAccess: true);
        Mainstay::drafts()->save(Post::class, ['title' => 'Second, again'], entry: $changed->id, locale: 'en', overrideAccess: true);
        $new = Mainstay::drafts()->save(Post::class, ['title' => 'Coming up'], locale: 'en', overrideAccess: true);

        $list = $this->get(route('mainstay.entries', 'post'))->assertOk();
        $list->assertSee('Coming up')->assertSee('Second, again')->assertSee('Hello');
        $list->assertSeeInOrder(['Second, again', 'Changed'])->assertSeeInOrder(['Coming up', 'Draft']);
        $list->assertSee(route('mainstay.drafts.edit', ['post', $new->id]), escape: false);
        $this->get(route('mainstay.entries', ['post', 'status' => 'Changed']))->assertSee('Second, again')->assertDontSee('Hello')->assertDontSee('Coming up');
        $this->get(route('mainstay.entries', ['post', 'query' => 'hel']))->assertSee('Hello')->assertDontSee('Second');

        /* In Dutch, which none of them has: each is listed, marked. */
        $this->assertMatchesRegularExpression('#>Hello</a>\s*<span class="ml-2 text-xs text-muted">Not in NL yet</span>#', $this->get(route('mainstay.entries', ['post', 'locale' => 'nl']))->getContent());
        $this->get(route('mainstay.entries', ['post', 'status' => ['x']]))->assertOk();

        foreach (range(1, 20) as $n) {
            Mainstay::create(Post::class, $this->values(['title' => "Post {$n}", 'slug' => "post-{$n}"]), locale: 'en', overrideAccess: true);
        }

        $this->get(route('mainstay.entries', ['post', 'field' => 'title', 'direction' => 'asc']))->assertSee('1–20 of 23')->assertDontSee('Second, again');
        $this->get(route('mainstay.entries', ['post', 'field' => 'title', 'direction' => 'asc', 'page' => 2]))->assertSee('21–23 of 23')->assertSee('Second, again');
        $this->assertNotNull($live);
    }

    #[Test]
    public function the_trash_restores_reporting_its_path_and_deletes_for_good(): void
    {
        $id = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true)->id;

        $this->post(route('mainstay.entries.delete', ['post', $id]))->assertRedirect(route('mainstay.entries', 'post'));
        $this->get(route('mainstay.entries', 'post'))->assertDontSee('Hello');
        $this->get(route('mainstay.entries.trash', 'post'))->assertSee('Hello')->assertSee('Delete for good');

        /* Its path taken meanwhile, so it comes back beside it. */
        Mainstay::create(Post::class, $this->values(['title' => 'Usurper']), locale: 'en', overrideAccess: true);
        $this->from(route('mainstay.entries.trash', 'post'))->post(route('mainstay.entries.restore', ['post', $id]))->assertSessionHas('status', 'Restored at /blog/hello-2.');

        Mainstay::delete(Post::class, $id, overrideAccess: true);
        $this->from(route('mainstay.entries.trash', 'post'))->post(route('mainstay.entries.destroy', ['post', $id]))->assertSessionHas('status', 'Deleted for good.');
        $this->assertSame(0, DB::table('post')->where('id', $id)->count());

        /* The selection, moved in one go. */
        $ids = [Mainstay::create(Post::class, $this->values(['slug' => 'a']), locale: 'en', overrideAccess: true)->id, Mainstay::create(Post::class, $this->values(['slug' => 'b']), locale: 'en', overrideAccess: true)->id];
        $this->from(route('mainstay.entries', 'post'))->post(route('mainstay.entries.trash-many', 'post'), ['rows' => array_map('strval', $ids)])->assertSessionHas('status', 'Moved 2 entries to the trash.');
        $this->assertSame(2, DB::table('post')->whereIn('id', $ids)->whereNotNull('deleted_at')->count());
    }

    #[Test]
    public function a_writer_is_offered_their_type_and_refused_the_rest(): void
    {
        $this->post(route('mainstay.entries.store', 'note'), ['title' => 'Theirs', 'slug' => 'theirs'])->assertSessionHasNoErrors();
        $flyer = Mainstay::create(Flyer::class, ['title' => 'Flyer', 'slug' => 'flyer'], locale: 'en', overrideAccess: true)->id;
        Mainstay::setFrontPage(Flyer::class, $flyer, overrideAccess: true);
        $this->signIn($this->user(['edit_notes', 'edit_published_notes', 'delete_notes']));
        $this->post(route('mainstay.entries.store', 'note'), ['title' => 'Mine', 'slug' => 'mine'])->assertSessionHasNoErrors();

        /* Another's unpublished draft is someone else's, and not listed. */
        $this->get(route('mainstay.entries', 'note'))->assertSee('Mine')->assertDontSee('Theirs');

        $this->get(route('mainstay.admin'))->assertOk()
            ->assertSee('href="/admin/note"', escape: false)
            ->assertDontSee('href="/admin/flyer"', escape: false)
            ->assertDontSee('href="/admin/banner"', escape: false);
        $this->get(route('mainstay.admin'))->assertSee('"label":"Notes"', escape: false);
        $this->get(route('mainstay.entries', 'flyer'))->assertForbidden();
        $this->get(route('mainstay.entries.create', 'flyer'))->assertForbidden();
        $this->get(route('mainstay.entries', 'banner'))->assertForbidden();

        $note = $this->get(route('mainstay.entries.create', 'note'))->assertOk();
        $note->assertSee('Save draft')->assertDontSee('>Publish<', escape: false);

        /* Not offered a trash they may not use. */
        $theirs = Mainstay::create(Note::class, ['title' => 'Kept', 'slug' => 'kept'], locale: 'en', overrideAccess: true)->id;
        $this->signIn($this->user(['edit_notes', 'edit_published_notes', 'edit_others_notes']));
        $this->get(route('mainstay.entries', 'note'))->assertSee('Kept')->assertDontSee(route('mainstay.entries.delete', ['note', $theirs]), escape: false);

        /* Making a note the front page moves the flyer, which is not a note
           publisher's to publish. */
        $this->signIn($this->user(['edit_notes', 'edit_published_notes', 'edit_others_notes', 'publish_notes']));
        $note = Mainstay::create(Note::class, ['title' => 'Note', 'slug' => 'note'], locale: 'en', overrideAccess: true)->id;
        $this->post(route('mainstay.entries.front', ['note', $note]))->assertForbidden();
        $this->assertSame([Flyer::class, $flyer], Mainstay::frontPage());

        Mainstay::setFrontPage(null, overrideAccess: true);
        $this->from(route('mainstay.entries.edit', ['note', $note]))->post(route('mainstay.entries.front', ['note', $note]))->assertSessionHasNoErrors();
        $this->assertSame([Note::class, $note], Mainstay::frontPage(), 'With no flyer to move, the note may be made the front page.');
    }

    #[Test]
    public function a_term_is_written_live_and_a_global_through_its_draft(): void
    {
        $this->post(route('mainstay.entries.store', 'topic'), ['title' => 'Design', 'slug' => 'design'])->assertSessionHasNoErrors();
        $this->assertSame('Design', Mainstay::findByUri('/topics/design', locale: 'en')->title);
        $this->get(route('mainstay.entries.create', 'topic'))->assertSee('>Save<', escape: false)->assertDontSee('Save draft');

        $form = $this->get(route('mainstay.entries', 'banner'))->assertOk()->assertSee('Save draft');
        $this->post(route('mainstay.entries.store', 'banner'), ['text' => 'Hello', ...$this->drawn($form)]);
        $this->assertNull(Mainstay::global(Banner::class, locale: 'en'));

        $form = $this->get(route('mainstay.entries', 'banner'));
        $this->post(route('mainstay.entries.store', 'banner'), ['text' => 'Hello', ...$this->drawn($form), 'intent' => 'publish'])->assertRedirect(route('mainstay.entries', 'banner'));
        $this->assertSame('Hello', Mainstay::global(Banner::class, locale: 'en')->text);
    }

    #[Test]
    public function an_entry_becomes_the_front_page_from_its_menu(): void
    {
        $id = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true)->id;

        $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('Use as front page');
        $this->from(route('mainstay.entries.edit', ['post', $id]))->post(route('mainstay.entries.front', ['post', $id]))->assertSessionHasNoErrors();

        $this->assertSame([Post::class, $id], Mainstay::frontPage());
        $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('Served at the site')->assertDontSee('Use as front page');
        $this->get(route('mainstay.entries', 'post'))->assertSee('Front page');

        $this->from(route('mainstay.entries.edit', ['post', $id]))->post(route('mainstay.entries.delete', ['post', $id]))->assertSessionHasErrors('front');
    }

    #[Test]
    public function a_path_under_the_admin_no_screen_answers_is_its_own_not_found(): void
    {
        foreach (['/admin/post/1/nothing', '/admin/nothing', '/admin/post/999', '/admin/post/drafts/999', '/admin/banner/new'] as $path) {
            $this->get($path)->assertNotFound()->assertSee('There is nothing here')->assertSee('aria-label="Sections"', escape: false);
        }

        $this->post('/admin/post/1/nothing')->assertNotFound()->assertSee('There is nothing here');
    }

    #[Test]
    public function a_field_type_without_its_component_is_named_and_a_handle_the_admin_uses_is_refused(): void
    {
        $this->declare(Accented::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
        $this->withoutExceptionHandling();

        $this->assertThrows(fn () => $this->get(route('mainstay.entries.create', 'article')), InvalidArgumentException::class, 'there is no view acme::components.fields.color-picker');
        $this->assertThrows(fn () => $this->declare(Login::class), InvalidArgumentException::class, 'would be called "login", which the admin uses for a screen of its own');
    }

    #[Test]
    public function an_unchanged_value_fingerprints_as_drawn_and_a_refused_one_never_does(): void
    {
        $field = Mainstay::fields(Post::class)['publishedAt'];

        $this->assertSame(Form::fingerprint($field, CarbonImmutable::parse('2026-10-07 12:30:00', 'UTC')), Form::fingerprint($field, '2026-10-07T14:30:00+02:00', posted: true));
        $this->assertNull(Form::fingerprint($field, 'not a date', posted: true));
        $this->assertNull(Form::fingerprint(Mainstay::fields(Post::class)['readingMinutes'], 'four', posted: true), 'Not cast to 0 and taken for an unchanged 0.');
        $this->assertSame("One\nTwo", Mainstay::fields(Post::class)['editorNote']->fromForm("One\r\nTwo"), 'A textarea\'s CRLF is stored as the lines it ends.');
    }

    #[Test]
    public function a_document_from_the_editor_reads_back_as_stored_and_one_outside_the_schema_is_refused(): void
    {
        $document = json_decode(file_get_contents(__DIR__.'/Fixtures/document.json'), true);

        $this->post(route('mainstay.entries.store', 'guide'), ['title' => 'Fields', 'body' => json_encode($document), 'aside' => '', 'intent' => 'publish'])->assertSessionHasNoErrors();
        $guide = Mainstay::find(Guide::class, locale: 'en', overrideAccess: true)->sole();
        $this->assertEquals($document, $guide->body->toArray());
        $this->assertNull($guide->aside, 'An editor left empty posts nothing, which is no document.');

        /* Drawn rendered for a browser without the editor, beside the JSON it
           posts back, untouched, as unchanged; one link dialog for the three
           editors. */
        $form = $this->get(route('mainstay.entries.edit', ['guide', $guide->id]))->assertOk()
            ->assertSee('<h2>Declared in code</h2>', escape: false)
            ->assertSee('data-rich-text-toolbar', escape: false);
        $this->assertSame(1, substr_count($form->getContent(), 'id="mainstay-link"'));
        $form->assertSee('name="aside" value=""', escape: false);
        /* Enter in the address submits with the dialog's first submit
           button, which has to be Apply rather than Remove link. */
        $this->assertMatchesRegularExpression('#id="mainstay-link".*?<button[^>]*type="submit"[^>]*value="apply"#s', $form->getContent());
        $this->assertDoesNotMatchRegularExpression('#id="mainstay-link".*?<button[^>]*type="submit"[^>]*value="remove".*?value="apply"#s', $form->getContent());
        preg_match('/name="body" value="([^"]*)"/', $form->getContent(), $body);
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['title' => 'Fields', 'body' => html_entity_decode($body[1]), 'aside' => '', ...$this->drawn($form)])
            ->assertSessionHas('status', 'Nothing to save: this is what is live.');

        /* A node the schema does not have is named, and JSON that does not
           parse is refused in the parser's words. */
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['body' => json_encode(['type' => 'doc', 'content' => [['type' => 'image']]])])
            ->assertSessionHasErrors(['body' => 'The body field has a node at content.0 of a type it does not know: image.']);
        $this->get(route('mainstay.entries.edit', ['guide', $guide->id]))->assertSee('id="field-body-error"', escape: false)->assertSee('of a type it does not know: image.');
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['body' => '{"type": "doc",'])
            ->assertSessionHasErrors(['body' => 'The body field does not parse as JSON: Syntax error.']);
        $this->get(route('mainstay.entries.edit', ['guide', $guide->id]))->assertSee('name="body" value="{&quot;type&quot;: &quot;doc&quot;,"', escape: false);
        $this->assertNull(Mainstay::drafts()->of(Guide::class, $guide->id, locale: 'en', overrideAccess: true));

        /* A document posted with a save another field refused is drawn as
           posted, rendered too. */
        $kept = ['type' => 'doc', 'content' => [['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Kept while refused']]]]];
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['body' => json_encode($kept), 'blocks' => '['])->assertSessionHasErrors('blocks');
        $this->get(route('mainstay.entries.edit', ['guide', $guide->id]))->assertSee('<h2>Kept while refused</h2>', escape: false);

        /* Two editors: one changes the body; the other, drawn before, changes
           the title and posts the body it drew with its keys in the editor's
           order rather than the column's. The first one's body stays. */
        $changed = $document;
        $changed['content'][0]['content'][0]['text'] = 'Changed by one';
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['title' => 'Fields', 'body' => json_encode($changed), ...$this->drawn($form)])->assertSessionHasNoErrors();
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['title' => 'Fields, by another', 'body' => json_encode($document), ...$this->drawn($form)])->assertSessionHasNoErrors();
        $draft = Mainstay::drafts()->of(Guide::class, $guide->id, locale: 'en', overrideAccess: true);
        $this->assertSame(['Fields, by another', 'Changed by one'], [$draft->entry->title, $draft->entry->body->toArray()['content'][0]['content'][0]['text']]);
    }

    #[Test]
    public function blocks_are_posted_as_json_and_a_wrong_one_is_listed_at_its_path(): void
    {
        $blocks = [
            ['type' => 'callout', 'data' => ['heading' => 'Mind the gap', 'tone' => 'warning']],
            ['type' => 'gallery', 'data' => ['slides' => [['type' => 'slide', 'data' => ['title' => 'One']]]]],
        ];

        $this->post(route('mainstay.entries.store', 'guide'), ['title' => 'Blocks', 'blocks' => json_encode($blocks), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $guide = Mainstay::find(Guide::class, locale: 'en', overrideAccess: true)->sole();
        $this->assertSame(['Mind the gap', 'warning', 'One'], [$guide->blocks[0]->heading, $guide->blocks[0]->tone, $guide->blocks[1]->slides[0]->title]);

        /* Pretty-printed in a textarea, which posts it back with its lines
           ending in CRLF: unchanged all the same. */
        $edit = route('mainstay.entries.edit', ['guide', $guide->id]);
        $form = $this->get($edit)->assertOk()->assertSee('Raw content');
        preg_match('#<textarea[^>]*name="blocks"[^>]*>(.*?)</textarea>#s', $form->getContent(), $raw);
        $this->assertStringContainsString("[\n    {\n", html_entity_decode($raw[1]));
        $this->post(route('mainstay.entries.update', ['guide', $guide->id]), ['title' => 'Blocks', 'blocks' => str_replace("\n", "\r\n", html_entity_decode($raw[1])), ...$this->drawn($form)])
            ->assertSessionHas('status', 'Nothing to save: this is what is live.');

        /* JSON that does not parse is refused, and comes back to be corrected. */
        $this->from($edit)->post(route('mainstay.entries.update', ['guide', $guide->id]), ['blocks' => '[{"type": "callout",'])
            ->assertSessionHasErrors(['blocks' => 'The blocks field does not parse as JSON: Syntax error.']);
        $refused = $this->get($edit)->assertSee('[{&quot;type&quot;: &quot;callout&quot;,', escape: false);
        $this->assertMatchesRegularExpression('#<details\s+open\s*>#', $refused->getContent(), 'Open, to show what is wrong.');

        /* A slide with no title is a draft's to hold and a publish's to
           refuse, listed above the textarea by where it is. */
        $blocks[1]['data']['slides'][0]['data']['title'] = '';
        $this->from($edit)->post(route('mainstay.entries.update', ['guide', $guide->id]), ['blocks' => json_encode($blocks), 'intent' => 'publish'])
            ->assertSessionHasErrors('blocks.1.data.slides.0.data.title');
        $this->assertSame('', Mainstay::drafts()->of(Guide::class, $guide->id, locale: 'en', overrideAccess: true)->entry->blocks[1]->slides[0]->title);
        $listed = $this->get($edit)->getContent();
        $this->assertMatchesRegularExpression('#The blocks\.1\.data\.slides\.0\.data\.title field is required\.</p>\s*</div>\s*<textarea#', $listed);
        $this->assertSame(1, substr_count($listed, 'field is required.'), 'Under its field, and not again above the form.');
    }

    #[Test]
    public function an_image_is_uploaded_described_picked_as_a_cover_and_trashed(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $alt = fn (int $id) => [Mainstay::media()->find($id, 'en')->alt, Mainstay::media()->find($id, 'nl')->alt];

        $this->post(route('mainstay.media.store'), ['file' => UploadedFile::fake()->image('harbour.png', 80, 60), 'alt' => ['en' => 'A harbour at dusk', 'nl' => 'Een haven in de schemering']])->assertSessionHasNoErrors();
        $harbour = Mainstay::media()->paginate()->first();
        $this->assertSame(['A harbour at dusk', 'Een haven in de schemering'], $alt($harbour->id));

        /* An alt left empty, which the host's middleware posts as null, marks
           the image decorative. */
        $this->post(route('mainstay.media.store'), ['file' => UploadedFile::fake()->image('rule.png', 90, 10), 'alt' => ['en' => '', 'nl' => '']])->assertSessionHasNoErrors();
        $rule = Mainstay::media()->paginate()->first();
        $this->assertSame(['', ''], $alt($rule->id));
        $this->post(route('mainstay.media.store'), ['alt' => ['en' => 'Nothing']])->assertSessionHasErrors(['file' => 'Choose an image to upload.']);
        $this->post(route('mainstay.media.store'), ['file' => UploadedFile::fake()->image('half.png', 30, 30), 'alt' => ['en' => 'Described in English only']])->assertSessionHasErrors('alt.nl');

        /* From the picker, the grid answers again, the new image first and
           picked; the picker is drawn without the shell. */
        $picker = $this->post(route('mainstay.media.store', ['pick' => 1]), ['file' => UploadedFile::fake()->image('pier.png', 70, 50), 'alt' => ['en' => 'A pier', 'nl' => 'Een pier']])->assertOk();
        $pier = Mainstay::media()->paginate()->first();
        $this->assertMatchesRegularExpression('/data-pick="'.$pier->id.'"[^>]*data-picked/', $picker->getContent());
        $this->get(route('mainstay.media', ['pick' => 1]))->assertOk()->assertDontSee('aria-label="Sections"', escape: false)->assertSee('data-pick="'.$harbour->id.'"', escape: false);
        $this->post(route('mainstay.media.store', ['pick' => 1]), ['file' => UploadedFile::fake()->image('again.png', 80, 60), 'alt' => ['en' => 'Again', 'nl' => 'Weer']])
            ->assertStatus(422)->assertSee("This image is already in the library, as image {$harbour->id}.")->assertSee('data-pick="'.$harbour->id.'"', escape: false);
        $this->get(route('mainstay.media'))->assertOk()->assertSee('aria-label="Sections"', escape: false)->assertSeeInOrder(['pier.png', 'rule.png', 'harbour.png']);

        /* Picked as a cover, and read back. */
        $this->post(route('mainstay.entries.store', 'pictured'), ['title' => 'Harbour', 'cover' => (string) $harbour->id, 'blocks' => '[]', 'intent' => 'publish'])->assertSessionHasNoErrors();
        $pictured = Mainstay::find(Pictured::class, locale: 'en', overrideAccess: true)->sole();
        $this->assertSame([$harbour->id, 'A harbour at dusk'], [$pictured->cover->id, $pictured->cover->alt]);
        $this->get(route('mainstay.entries.edit', ['pictured', $pictured->id]))->assertSee('name="cover" value="'.$harbour->id.'"', escape: false)->assertSee('-640.webp')->assertSee('id="mainstay-picker"', escape: false);

        /* Its focal point moved, and its alt text changed in one language. */
        $this->post(route('mainstay.media.update', $harbour->id), ['focal' => ['20', '80'], 'alt' => ['en' => 'A harbour at night', 'nl' => 'Een haven in de schemering']])->assertSessionHasNoErrors();
        $this->assertSame([20, 80], Mainstay::media()->find($harbour->id)->focal);
        $this->assertSame(['A harbour at night', 'Een haven in de schemering'], $alt($harbour->id));

        /* The trash: out of the grid, shown as missing on the entry and kept
           there, back again; and gone for good. */
        $this->post(route('mainstay.media.delete', $harbour->id))->assertRedirect(route('mainstay.media'));
        $this->get(route('mainstay.media'))->assertDontSee('harbour.png');
        $this->get(route('mainstay.media.trash'))->assertSee('harbour.png');
        $this->get(route('mainstay.entries.edit', ['pictured', $pictured->id]))->assertSee('Missing')->assertSee('name="cover" value="'.$harbour->id.'"', escape: false);
        $this->post(route('mainstay.media.restore', $harbour->id))->assertRedirect(route('mainstay.media.trash'));
        $this->assertNotNull(Mainstay::media()->find($harbour->id));
        $this->post(route('mainstay.media.delete', $rule->id));
        $this->post(route('mainstay.media.destroy', $rule->id))->assertRedirect(route('mainstay.media.trash'));
        $this->assertSame(0, Mainstay::media()->paginate(trashed: true)->total());
        $this->post(route('mainstay.media.destroy', 999))->assertNotFound()->assertSee('aria-label="Sections"', escape: false);

        /* Offered to whoever may read the library, as its reads ask, with
           Upload for whoever may upload, and each write on an image for
           whoever the library would let make it; a host's policy closing
           the library closes the link and the screens alike. */
        $edit = $this->get(route('mainstay.media.edit', $harbour->id))->assertSee('form="media-form"', escape: false)->assertSee('Move to trash');
        $this->assertMatchesRegularExpression('/<div\s+data-focal\s+class/', $edit->getContent());
        $this->post(route('mainstay.media.delete', $pier->id));
        $this->get(route('mainstay.media.trash'))->assertSee('Restore')->assertSee('Delete for good');

        $this->signIn($this->user(['upload_media']));
        $this->get(route('mainstay.media'))->assertSee('data-dialog-open="upload"', escape: false);
        $edit = $this->get(route('mainstay.media.edit', $harbour->id))->assertDontSee('form="media-form"', escape: false)->assertDontSee('Move to trash')->assertSee('<fieldset disabled', escape: false);
        $this->assertDoesNotMatchRegularExpression('/<div\s+data-focal\s+class/', $edit->getContent(), 'No focal point to set where it cannot be saved.');
        $this->get(route('mainstay.media.trash'))->assertSee('pier.png')->assertDontSee('Restore')->assertDontSee('Delete for good');

        $this->signIn($this->user(Capabilities::of(Story::class)));
        $this->get(route('mainstay.admin'))->assertSee('href="/admin/media"', escape: false);
        $this->get(route('mainstay.media'))->assertOk()->assertDontSee('data-dialog-open="upload"', escape: false);
        $this->get(route('mainstay.media', ['pick' => 1]))->assertOk()->assertSee('data-pick="'.$harbour->id.'"', escape: false)->assertDontSee('Upload a new one');
        $this->get(route('mainstay.media.edit', $harbour->id))->assertDontSee('form="media-form"', escape: false);
        $this->post(route('mainstay.media.update', $harbour->id), ['alt' => ['en' => 'Mine now']])->assertForbidden();

        Gate::policy(Media::class, ClosedPolicy::class);
        $this->get(route('mainstay.admin'))->assertDontSee('href="/admin/media"', escape: false);
        $this->get(route('mainstay.media'))->assertForbidden();
        $this->get(route('mainstay.media', ['pick' => 1]))->assertForbidden();
    }

    #[Test]
    public function relations_are_posted_in_an_order_and_read_back_in_it(): void
    {
        $make = fn (string $type, string $title) => Mainstay::create($type, ['title' => $title, 'slug' => strtolower($title)], locale: 'en', overrideAccess: true);
        [$ada, $bo, $one, $two, $three] = [$make(Person::class, 'Ada'), $make(Person::class, 'Bo'), $make(Story::class, 'One'), $make(Story::class, 'Two'), $make(Story::class, 'Three')];

        $this->post(route('mainstay.entries.store', 'story'), [
            'title' => 'Four', 'slug' => 'four', 'blocks' => '[]', 'genres' => [''],
            'author' => (string) $ada->id,
            'related' => ['', (string) $three->id, (string) $one->id],
            'pick' => "story:{$two->id}",
            'picks' => ['', "person:{$bo->id}", "story:{$one->id}", "person:{$ada->id}"],
            'intent' => 'publish',
        ])->assertSessionHasNoErrors();
        $four = Mainstay::findByUri('/stories/four', locale: 'en');
        $refer = fn (array $entries) => array_map(fn ($entry) => [class_basename($entry), $entry->id], $entries);

        $this->assertSame($ada->id, $four->author->id);
        $this->assertSame([$three->id, $one->id], array_column($four->related, 'id'));
        $this->assertSame([['Story', $two->id]], $refer([$four->pick]));
        $this->assertSame([['Person', $bo->id], ['Story', $one->id], ['Person', $ada->id]], $refer($four->picks));

        /* Drawn as rows in that order, the type named where there are
           several; the search finds titles across the types. */
        $form = $this->get(route('mainstay.entries.edit', ['story', $four->id]))
            ->assertSeeInOrder(['name="related[]" value="'.$three->id.'"', 'name="related[]" value="'.$one->id.'"'], escape: false)
            ->assertSeeInOrder(['name="picks[]" value="person:'.$bo->id.'"', 'name="picks[]" value="story:'.$one->id.'"'], escape: false);
        $this->assertMatchesRegularExpression('#value="person:'.$bo->id.'">\s*<span data-relation-title[^>]*>Bo</span>\s*<span data-relation-type[^>]*>Person</span>#', $form->getContent(), 'Each row with its title and, among several types, its type.');
        $this->get(route('mainstay.relations', ['story', 'picks', 'q' => 'O']))
            ->assertSee('data-value="person:'.$bo->id.'"', escape: false)
            ->assertSee('data-value="story:'.$two->id.'"', escape: false)
            ->assertDontSee('data-value="person:'.$ada->id.'"', escape: false);
        $this->get(route('mainstay.relations', ['story', 'title', 'q' => 'O']))->assertNotFound();
        $this->get(route('mainstay.relations', ['story', 'genres', 'q' => 'O']))->assertNotFound();

        /* In the form's locale: an entry with no Dutch is not found in Dutch. */
        Mainstay::update(Story::class, $two->id, ['title' => 'Twee', 'slug' => 'twee'], locale: 'nl', overrideAccess: true);
        $this->get(route('mainstay.relations', ['story', 'related', 'q' => 'T', 'locale' => 'nl']))->assertSee('data-title="Twee"', escape: false)->assertDontSee('Three');
        $this->get(route('mainstay.relations', ['story', 'related', 'q' => 'T']))->assertSee('data-title="Two"', escape: false)->assertSee('data-title="Three"', escape: false);

        /* Twenty at most. */
        foreach (range(1, 21) as $n) {
            Mainstay::create(Story::class, ['title' => "Many {$n}", 'slug' => "many-{$n}"], locale: 'en', overrideAccess: true);
        }

        $this->assertSame(20, substr_count($this->get(route('mainstay.relations', ['story', 'related', 'q' => 'Many']))->getContent(), 'data-relation-pick'));

        /* Reordered, and then emptied: none left. */
        $this->post(route('mainstay.entries.update', ['story', $four->id]), ['related' => ['', (string) $one->id, (string) $three->id], ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $this->assertSame([$one->id, $three->id], array_column(Mainstay::findById(Story::class, $four->id, locale: 'en')->related, 'id'));

        $form = $this->get(route('mainstay.entries.edit', ['story', $four->id]));
        $this->post(route('mainstay.entries.update', ['story', $four->id]), ['related' => [''], 'picks' => [''], 'author' => '', 'pick' => '', ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $four = Mainstay::findById(Story::class, $four->id, locale: 'en');
        $this->assertSame([[], [], null, null], [$four->related, $four->picks, $four->author, $four->pick]);
    }

    #[Test]
    public function tags_are_picked_by_id_and_created_by_text_in_every_language(): void
    {
        $crime = Mainstay::create(Genre::class, ['title' => 'Crime', 'slug' => 'crime'], locale: 'en', overrideAccess: true);
        Mainstay::update(Genre::class, $crime->id, ['title' => 'Misdaad', 'slug' => 'misdaad'], locale: 'nl', overrideAccess: true);
        $genre = fn (string $slug, string $locale = 'en') => Mainstay::find(Genre::class, where: ['slug' => $slug], locale: $locale)->first();

        $this->get(route('mainstay.entries.create', 'story'))->assertSee('<option value="Crime" data-value="id:'.$crime->id.'"></option>', escape: false);
        $this->get(route('mainstay.entries.create', ['story', 'locale' => 'nl']))->assertSee('<option value="Misdaad" data-value="id:'.$crime->id.'"></option>', escape: false);

        /* One picked, three typed: a new one, a number, and one whose slug a
           term already has, which is that term and kept once. */
        $this->post(route('mainstay.entries.store', 'story'), ['title' => 'Five', 'slug' => 'five', 'blocks' => '[]', 'genres' => ['', "id:{$crime->id}", 'new:Science fiction', 'new:2026', 'new:Crime'], 'intent' => 'publish'])->assertSessionHasNoErrors();
        $five = Mainstay::findByUri('/stories/five', locale: 'en');
        $this->assertSame(['Crime', 'Science fiction', '2026'], array_column($five->genres, 'title'));
        $this->assertSame(['Science fiction', 'science-fiction'], [$genre('science-fiction', 'nl')->title, $genre('science-fiction', 'nl')->slug], 'Written in every locale alike.');
        $this->get(route('mainstay.entries.edit', ['story', $five->id]))
            ->assertSee('value="id:'.$genre('2026')->id.'"', escape: false)
            ->assertSeeInOrder(['<span data-tag-label>Crime</span>', '<span data-tag-label>Science fiction</span>', '<span data-tag-label>2026</span>'], escape: false);

        /* A save refused creates none, and a title that makes no slug is
           named. */
        $this->post(route('mainstay.entries.update', ['story', $five->id]), ['title' => str_repeat('x', 300), 'genres' => ['', 'new:Western']])->assertSessionHasErrors('title');
        $this->assertNull($genre('western'));
        $this->post(route('mainstay.entries.update', ['story', $five->id]), ['genres' => ['', 'new:!!!']])->assertSessionHasErrors(['genres' => 'The tag "!!!" makes no slug. Give it a letter or a digit.']);
        $this->post(route('mainstay.entries.update', ['story', $five->id]), ['genres' => ['', 'new:'.str_repeat('y', 300)]])
            ->assertSessionHasErrors(['genres' => 'The tag "'.str_repeat('y', 40).'..." was refused: The title field must not be greater than 255 characters. The slug field must not be greater than 255 characters.'])
            ->assertSessionDoesntHaveErrors(['title', 'slug']);

        /* A slug a term has in another language is that term: typed in the
           English form as its Dutch title, it is Crime. */
        $form = $this->get(route('mainstay.entries.edit', ['story', $five->id]));
        $this->post(route('mainstay.entries.update', ['story', $five->id]), ['genres' => ['', 'new:Misdaad'], ...$this->drawn($form)])->assertSessionHasNoErrors();
        $this->assertSame([$crime->id], array_column(Mainstay::drafts()->of(Story::class, $five->id, locale: 'en', overrideAccess: true)->entry->genres, 'id'));
        $this->assertCount(3, Mainstay::find(Genre::class, locale: 'en'), 'Crime, Science fiction and 2026, and nothing new.');

        /* Without manage, creating is refused naming it, and the save with
           it; picking is not. */
        $this->signIn($this->user(Capabilities::of(Story::class)));
        $this->post(route('mainstay.entries.store', 'story'), ['title' => 'Six', 'slug' => 'six', 'genres' => ['', 'new:Horror']])->assertSessionHasErrors(['genres' => 'Adding a new tag needs manage_genres.']);
        $this->assertNull($genre('horror'));
        $this->assertCount(0, Mainstay::drafts()->all(Story::class, locale: 'en', overrideAccess: true)->filter(fn ($draft) => $draft->entryId === null), 'No new story drafted.');
        $this->post(route('mainstay.entries.store', 'story'), ['title' => 'Six', 'slug' => 'six', 'genres' => ['', "id:{$crime->id}"]])->assertSessionHasNoErrors();

        /* The last tag removed leaves none. */
        $this->signIn($this->user(role: 'administrator'));
        $form = $this->get(route('mainstay.entries.edit', ['story', $five->id]));
        $this->post(route('mainstay.entries.update', ['story', $five->id]), ['genres' => [''], ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $this->assertSame([], Mainstay::findById(Story::class, $five->id, locale: 'en')->genres);
    }

    #[Test]
    public function a_form_draws_what_its_fields_point_at_as_missing_where_its_reader_may_not_read_it(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $crime = Mainstay::create(Genre::class, ['title' => 'Crime', 'slug' => 'crime'], locale: 'en', overrideAccess: true);
        $ada = Mainstay::create(Person::class, ['title' => 'Ada', 'slug' => 'ada'], locale: 'en', overrideAccess: true);
        $one = Mainstay::create(Story::class, ['title' => 'One', 'slug' => 'one', 'author' => $ada->id, 'genres' => [$crime->id]], locale: 'en', overrideAccess: true);
        $cover = Mainstay::media()->upload(UploadedFile::fake()->image('cover.png', 40, 30), ['en' => 'A cover', 'nl' => 'Een omslag'], overrideAccess: true);
        $edit = route('mainstay.entries.edit', ['story', $one->id]);

        /* A host's policies closing the taxonomy, the relation's type and
           the library to every reader. */
        Gate::policy(Genre::class, ClosedPolicy::class);
        Gate::policy(Person::class, ClosedPolicy::class);
        Gate::policy(Media::class, ClosedPolicy::class);

        $this->get($edit)->assertOk()->assertSee('<span data-tag-label>Missing ('.$crime->id.')</span>', escape: false)->assertSee('value="'.$ada->id.'"', escape: false);

        /* And after a refused save, the posted references are drawn again
           the same way. */
        $this->from($edit)->post(route('mainstay.entries.update', ['story', $one->id]), ['title' => str_repeat('x', 300), 'author' => (string) $ada->id, 'genres' => ['', "id:{$crime->id}"]])->assertSessionHasErrors('title');
        $this->get($edit)->assertOk()->assertSee('value="'.$ada->id.'"', escape: false)->assertSee('<span data-tag-label>Missing ('.$crime->id.')</span>', escape: false);
        $create = route('mainstay.entries.create', 'pictured');
        $this->from($create)->post(route('mainstay.entries.store', 'pictured'), ['title' => str_repeat('x', 300), 'cover' => (string) $cover->id])->assertSessionHasErrors('title');
        $this->get($create)->assertOk()->assertSee('name="cover" value="'.$cover->id.'"', escape: false)->assertSee('Missing');

        /* A search leaves out the type it may not read. */
        $this->get(route('mainstay.relations', ['story', 'picks', 'q' => 'a']))->assertOk()->assertDontSee('person:');
    }

    #[Test]
    public function history_names_who_published_each_version_and_restores_one_as_the_draft(): void
    {
        /* A seeder's write is a script's. */
        $id = Mainstay::create(Post::class, $this->values(), locale: 'en', overrideAccess: true)->id;
        $history = route('mainstay.entries.history', ['post', $id]);
        $this->get($history)->assertOk()->assertSee('Published by a script')->assertSee('No earlier versions');
        $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('href="'.$history.'"', escape: false);

        /* Two publishes by two people, named newest first. */
        [$ada, $bo, $cy] = [$this->user(role: 'administrator'), $this->user(role: 'administrator'), $this->user(role: 'administrator')];

        foreach ([[$ada, 'By Ada'], [$bo, 'By Bo']] as [$user, $title]) {
            $this->signIn($user);
            $form = $this->get(route('mainstay.entries.edit', ['post', $id]));
            $this->post(route('mainstay.entries.update', ['post', $id]), [...$this->values(['title' => $title]), ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
            $this->get($history)->assertSeeInOrder(['Live since', "Published by {$user->name}", 'Live until', 'Restore'], escape: false);
        }

        $this->get($history)->assertSeeInOrder(['Live since', "Published by {$bo->name}", 'Live until', "Published by {$ada->name}", 'Live until', 'Published by a script']);

        /* A translation added names the third on the live version, and
           files nothing. */
        $this->signIn($cy);
        $form = $this->get(route('mainstay.entries.edit', ['post', $id, 'locale' => 'nl']));
        $this->post(route('mainstay.entries.update', ['post', $id, 'locale' => 'nl']), [...$this->values(['title' => 'Hallo', 'slug' => 'hallo']), ...$this->drawn($form), 'intent' => 'publish'])->assertSessionHasNoErrors();
        $page = $this->get($history)->assertSeeInOrder(['Live since', "Published by {$cy->name}", 'Live until', "Published by {$ada->name}", 'Live until', 'Published by a script'])->getContent();
        $this->assertSame(2, substr_count($page, 'Live until'));

        /* An account gone since is named as one. */
        $ada->delete();
        $this->get($history)->assertSee('Published by a deleted account');

        /* The script's version, holding a field the type has dropped since
           and a language no longer configured, restored: the form opens on
           it as the draft and says what it could not bring back. */
        $script = Mainstay::revisions()->of(Post::class, $id, overrideAccess: true)->last();
        $snapshot = json_decode(DB::table('mainstay_revisions')->where('id', $script->id)->value('snapshot'), true);
        $snapshot['fields']['subtitle'] = 'Dropped';
        $snapshot['locales']['de'] = ['title' => 'Hallo'];
        DB::table('mainstay_revisions')->where('id', $script->id)->update(['snapshot' => json_encode($snapshot)]);

        $this->post(route('mainstay.revisions.restore', ['post', $id, $script->id]))
            ->assertRedirect(route('mainstay.entries.edit', ['post', $id]))
            ->assertSessionHas('status', 'That version is the draft now. Look it over, then publish it.');
        $this->get(route('mainstay.entries.edit', ['post', $id]))->assertSee('Not brought back -- subtitle: The type no longer declares it. DE title: de is no longer a content locale.')->assertSee('Unpublished changes')->assertSee('value="Hello"', escape: false);

        /* From the Dutch history, back to the Dutch form; and the form in a
           language the entry has no version in still links its history. */
        $this->post(route('mainstay.revisions.restore', ['post', $id, $script->id, 'locale' => 'nl']))->assertRedirect(route('mainstay.entries.edit', ['post', $id, 'locale' => 'nl']));
        $this->assertMatchesRegularExpression('#action="[^"]*/revisions/\d+\?locale=nl"#', $this->get(route('mainstay.entries.history', ['post', $id, 'locale' => 'nl']))->getContent());
        $untranslated = Mainstay::create(Post::class, $this->values(['slug' => 'only-english']), locale: 'en', overrideAccess: true);
        $this->get(route('mainstay.entries.edit', ['post', $untranslated->id, 'locale' => 'nl']))->assertSee('There is no NL version yet')->assertSee('Every version and who published it');
        $this->assertSame('Hello', Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true)->entry->title);

        /* Restored and published, the same version restored again is what
           is live, which the history says. */
        $draft = Mainstay::drafts()->of(Post::class, $id, locale: 'en', overrideAccess: true);
        Mainstay::drafts()->publish($draft->id, overrideAccess: true);
        $this->post(route('mainstay.revisions.restore', ['post', $id, $script->id]))
            ->assertRedirect($history)->assertSessionHas('status', 'Nothing to restore: that version is what is live.');

        /* Live since it replaced the version before, which a write changing
           nothing a day later does not move. */
        $went = Mainstay::revisions()->of(Post::class, $id, overrideAccess: true)->first()->createdAt->setTimezone('UTC');
        $this->travel(1)->days();
        Mainstay::update(Post::class, $id, ['title' => 'Hello'], locale: 'en', overrideAccess: true);
        $this->get($history)->assertSee('Live since '.$went->format('j F Y, H:i').' UTC');
        $this->travelBack();

        /* A revision is restored only under the entry it is of, and an
           address naming none, or a term, is not found. The history of an
           entry in the trash is not found either. */
        $this->post(route('mainstay.revisions.restore', ['post', $untranslated->id, $script->id]))->assertNotFound()->assertSee('aria-label="Sections"', escape: false);
        $this->post(route('mainstay.globals.revisions.restore', ['post', $script->id]))->assertNotFound()->assertSee('aria-label="Sections"', escape: false);
        Mainstay::delete(Post::class, $untranslated->id, overrideAccess: true);
        $this->get(route('mainstay.entries.history', ['post', $untranslated->id]))->assertNotFound()->assertSee('aria-label="Sections"', escape: false);

        /* An entry with only a Dutch version has its history in English
           too, named by its Dutch title. */
        $dutch = Mainstay::create(Post::class, $this->values(['title' => 'Alleen Nederlands', 'slug' => 'alleen-nederlands']), locale: 'nl', overrideAccess: true);
        $this->get(route('mainstay.entries.history', ['post', $dutch->id]))->assertOk()->assertSee('History of Alleen Nederlands');

        /* A global has its history too, linked from its form, once saved as
           well as before; a list and a term have none. */
        $this->get(route('mainstay.globals.history', 'banner'))->assertOk()->assertSee('No earlier versions');
        Mainstay::saveGlobal(Banner::class, ['text' => 'Hello'], locale: 'en', overrideAccess: true);
        $this->get(route('mainstay.entries', 'banner'))->assertSee('href="'.route('mainstay.globals.history', 'banner').'"', escape: false);
        $this->get(route('mainstay.globals.history', 'banner'))->assertOk()->assertSee('Live since')->assertSee('Published by');
        Mainstay::saveGlobal(Banner::class, ['text' => 'Hello again'], locale: 'en', overrideAccess: true);
        $banner = Mainstay::revisions()->of(Banner::class, overrideAccess: true)->sole();
        $this->post(route('mainstay.revisions.restore', ['post', $id, $banner->id]))->assertNotFound();
        $this->post(route('mainstay.globals.revisions.restore', ['banner', $script->id]))->assertNotFound();
        $this->post(route('mainstay.globals.revisions.restore', ['banner', $banner->id]))->assertRedirect(route('mainstay.entries', 'banner'));
        $this->assertSame('Hello', Mainstay::drafts()->of(Banner::class, locale: 'en', overrideAccess: true)->entry->text);
        $this->get(route('mainstay.globals.history', 'post'))->assertNotFound();
        $topic = Mainstay::create(Topic::class, ['title' => 'Topic', 'slug' => 'topic'], locale: 'en', overrideAccess: true);
        $this->get(route('mainstay.entries.history', ['topic', $topic->id]))->assertNotFound();
        $this->get(route('mainstay.entries.edit', ['topic', $topic->id]))->assertOk()->assertDontSee('Every version and who published it');
    }

    #[Test]
    public function a_relation_an_image_and_tags_show_what_is_wrong_with_them_under_their_names(): void
    {
        $errors = fn (array $messages) => ['errors' => (new ViewErrorBag)->put('default', new MessageBag($messages))];
        $story = $this->withSession($errors([
            'related.0' => ['The related.0 field is not there to point at.'],
            'author' => ['The author field is gone.'],
            'genres' => ['Adding a new tag needs manage_genres.'],
        ]))->get(route('mainstay.entries.create', 'story'))->getContent();
        $pictured = $this->withSession($errors(['cover' => ['The cover field is not in the library.']]))->get(route('mainstay.entries.create', 'pictured'))->getContent();

        foreach (['related.0 field is not there', 'author field is gone', 'needs manage_genres', 'cover field is not in the library'] as $message) {
            $this->assertSame(1, substr_count($story.$pictured, $message), "Under its field, and not again above the form: {$message}");
        }
    }
}
