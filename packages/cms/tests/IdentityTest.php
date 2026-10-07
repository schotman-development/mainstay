<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate as HostGate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Pluralizer;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\ResetPassword;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Http\Authenticate;
use Mainstay\MainstayServiceProvider;
use Mainstay\Tests\Fixtures\Policies\RecordingPolicy;
use Mainstay\Tests\Fixtures\Signed\Banner;
use Mainstay\Tests\Fixtures\Signed\Entry as EntryType;
use Mainstay\Tests\Fixtures\Signed\Flyer;
use Mainstay\Tests\Fixtures\Signed\Footer;
use Mainstay\Tests\Fixtures\Signed\Media as MediaType;
use Mainstay\Tests\Fixtures\Signed\Note;
use Mainstay\Tests\Fixtures\Signed\Notes;
use Mainstay\Tests\Fixtures\Signed\Topic;
use Mainstay\Tests\Fixtures\Signed\User as UserType;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;

/*
 | The phase 9 check: Mainstay's own accounts, signing in and out of the admin
 | under a session of its own, a forgotten password, and what a user may do --
 | capabilities derived from the types, held through a role, and mapped
 | through ownership by the policies the query layer asks.
 */
class IdentityTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $made = 0;

    /** @var list<string> */
    private array $files = [];

    /*
     | Sessions in cookies, so a request sees what the browser sends and
     | nothing the last request left on the server: see browser().
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'cookie');
    }

    /* A note's internal memo, read by the layer behind the admin's stack and
       behind the host's `web` group. */
    protected function defineRoutes($router): void
    {
        $memo = fn (int $id) => ['memo' => Mainstay::findById(Note::class, $id, locale: 'en')?->memo ?? null];

        $router->middleware(['mainstay', Authenticate::class])->get('stack/notes/{id}', $memo);
        $router->middleware(['mainstay', Authenticate::class])->post('stack/notes', fn () => [
            'owner' => Mainstay::create(Note::class, ['title' => 'Stacked', 'slug' => 'stacked'], locale: 'en')->ownerId,
        ]);
        $router->middleware('web')->get('public/notes/{id}', $memo);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        $this->declare(Note::class, Flyer::class, Banner::class, Footer::class, Topic::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), array_filter($this->files, is_file(...)));

        parent::tearDown();
    }

    /* An account holding these capabilities through a role of its own, or
       holding the role named. */
    private function user(array $capabilities = [], array $grants = [], array $denials = [], ?string $role = null): User
    {
        $n = ++$this->made;
        $role = $role === null
            ? Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities])
            : Role::query()->where('name', $role)->sole();

        return User::query()->create([
            'name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => self::PASSWORD,
            'role_id' => $role->id, 'grants' => $grants, 'denials' => $denials,
        ]);
    }

    /* The layer called as this user, as the admin's middleware leaves it. */
    private function signIn(?User $user): void
    {
        $this->app['request']->attributes->set(Gate::USER, $user);
    }

    private function note(string $slug = 'first', ?int $owner = null): Note
    {
        $note = Mainstay::create(Note::class, ['title' => 'First', 'slug' => $slug, 'memo' => 'Check'], locale: 'en', overrideAccess: true);
        DB::table('note')->where('id', $note->id)->update(['owner_id' => $owner]);

        return $note;
    }

    private function image(?int $owner): int
    {
        return DB::table('mainstay_media')->insertGetId([
            'hash' => hash('sha256', Str::random()), 'type' => 'image/png', 'name' => 'cover.png', 'bytes' => 1, 'width' => 8, 'height' => 8,
            'focal_x' => 50, 'focal_y' => 50, 'alt' => json_encode(['en' => 'A cover']), 'owner_id' => $owner,
            'created_at' => '2026-10-07 00:00:00', 'updated_at' => '2026-10-07 00:00:00',
        ]);
    }

    private function png(): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagesetpixel($image, 0, 0, imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        $this->files[] = $path = tempnam(sys_get_temp_dir(), 'mainstay-media-');
        imagepng($image, $path);

        return $path;
    }

    /*
     | The next request as a browser holding these cookies and nothing else.
     | The guards and the session store forget what the last request left
     | them, which in a real one would be another process's: the store is
     | emptied rather than replaced, since the redirector holds on to it, and
     | the cookies the last one queued are dropped.
     */
    private function browser(array $cookies = []): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withUnencryptedCookies($cookies);
    }

    /* The browser's cookies after this response. */
    private function keep(array $jar, TestResponse $response): array
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($jar[$cookie->getName()]);
            } else {
                $jar[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $jar;
    }

    private function login(User $user, array $jar = [], bool $remember = false, string $password = self::PASSWORD): array
    {
        $response = $this->browser($jar)->post(route('mainstay.login'), ['email' => $user->email, 'password' => $password, 'remember' => $remember ? '1' : null]);
        $response->assertRedirect()->assertSessionHasNoErrors();

        return $this->keep($jar, $response);
    }

    private function stored(string $key): mixed
    {
        return $this->app['session.store']->get($key);
    }

    #[Test]
    public function capabilities_are_derived_from_the_registered_types_in_two_forms(): void
    {
        $this->assertEqualsCanonicalizing([
            'edit_notes', 'edit_published_notes', 'edit_others_notes', 'publish_notes', 'delete_notes', 'delete_others_notes',
            'edit_flyers', 'edit_published_flyers', 'edit_others_flyers', 'publish_flyers', 'delete_flyers', 'delete_others_flyers',
            'edit_banner', 'publish_banner', 'edit_footer', 'publish_footer',
            'manage_topics',
            'edit_entries', 'edit_published_entries', 'edit_others_entries', 'publish_entries', 'delete_entries', 'delete_others_entries',
            'edit_globals', 'publish_globals', 'manage_terms', 'upload_media', 'edit_others_media', 'manage_users',
        ], Mainstay::capabilities());
    }

    #[Test]
    public function capabilities_are_english_plurals_whatever_language_the_host_pluralizes_in(): void
    {
        Pluralizer::useLanguage('spanish');

        try {
            $this->assertSame('edit_notes', Capabilities::of(Note::class)[0]);
            $this->assertSame('manage_topics', Capabilities::of(Topic::class)[0]);
        } finally {
            Pluralizer::useLanguage('english');
        }
    }

    #[Test]
    public function a_type_deriving_a_capability_already_named_is_refused(): void
    {
        foreach ([EntryType::class => 'edit_entries', MediaType::class => 'edit_others_media', UserType::class => 'manage_users', Notes::class => 'edit_notes'] as $type => $name) {
            $this->assertThrows(fn () => Mainstay::types([$type]), InvalidArgumentException::class, "would derive the capability \"{$name}\"");
            $this->assertNotContains($type, Mainstay::registered());
        }
    }

    #[Test]
    public function a_capability_is_held_through_the_role_star_or_a_grant_and_lost_to_a_denial_of_either_form(): void
    {
        $names = Capabilities::names(Note::class, 'edit');

        $this->assertSame(['edit_notes', 'edit_entries'], $names);

        foreach ([[['edit_notes']], [['edit_entries']], [['*']], [[], ['edit_notes']], [[], ['edit_entries']]] as $held) {
            $this->assertTrue($this->user(...$held)->holds(...$names), json_encode($held));
        }

        foreach ([[['edit_flyers']], [[], ['*']], [['edit_entries'], [], ['edit_notes']], [['edit_notes'], [], ['edit_entries']], [['*'], [], ['edit_notes']], [[], ['edit_notes'], ['edit_notes']]] as $held) {
            $this->assertFalse($this->user(...$held)->holds(...$names), json_encode($held));
        }
    }

    #[Test]
    public function the_migrations_ship_an_administrator_and_an_editor(): void
    {
        $this->assertSame(
            ['administrator' => ['*'], 'editor' => array_values(array_diff(Capabilities::shared(), ['manage_users']))],
            Role::query()->orderBy('name')->pluck('capabilities', 'name')->all(),
        );
    }

    #[Test]
    public function someone_elses_entry_needs_edit_others_and_one_nobody_owns_is_someone_elses(): void
    {
        $author = $this->user(['edit_notes', 'edit_published_notes', 'publish_notes', 'delete_notes']);
        $own = $this->note('own', $author->id);
        $theirs = $this->note('theirs', $this->user(['*'])->id);
        $nobodys = $this->note('nobodys');

        $this->signIn($author);

        $this->assertSame('Mine', Mainstay::update(Note::class, $own->id, ['title' => 'Mine'], locale: 'en')->title);
        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Mine again'], entry: $own->id, locale: 'en');
        $this->assertSame('Mine again', Mainstay::drafts()->publish($draft->id)->title);

        foreach ([$theirs, $nobodys] as $note) {
            $waiting = Mainstay::drafts()->save(Note::class, ['title' => 'Waiting'], entry: $note->id, locale: 'en', overrideAccess: true);
            $this->assertThrows(fn () => Mainstay::drafts()->publish($waiting->id), AuthorizationException::class, 'This needs edit_others_notes.');
            $this->assertThrows(fn () => Mainstay::update(Note::class, $note->id, ['title' => 'Taken'], locale: 'en'), AuthorizationException::class, 'This needs edit_others_notes.');
            $this->assertThrows(fn () => Mainstay::drafts()->save(Note::class, ['title' => 'Taken'], entry: $note->id, locale: 'en'), AuthorizationException::class, 'This needs edit_others_notes.');
            $this->assertThrows(fn () => Mainstay::delete(Note::class, $note->id), AuthorizationException::class, 'This needs delete_others_notes.');
        }

        Mainstay::delete(Note::class, $own->id);
        $this->assertSame([], Mainstay::find(Note::class, ['slug' => 'own'], locale: 'en')->all());

        Mainstay::delete(Note::class, $theirs->id, overrideAccess: true);
        $this->assertThrows(fn () => Mainstay::restore(Note::class, $theirs->id), AuthorizationException::class, 'This needs delete_others_notes.');
    }

    #[Test]
    public function a_role_of_edit_alone_drafts_new_entries_of_its_own_and_nothing_more(): void
    {
        $contributor = $this->user(['edit_notes']);
        $live = $this->note('live', $contributor->id);

        $this->signIn($this->user(['edit_notes']));
        $theirs = Mainstay::drafts()->save(Note::class, ['title' => 'Theirs'], locale: 'en');

        $this->signIn($contributor);
        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Mine'], locale: 'en');
        $this->assertSame('Mine again', Mainstay::drafts()->save(Note::class, ['title' => 'Mine again'], draft: $draft->id, locale: 'en')->entry->title);
        $this->assertSame('Mine again', Mainstay::drafts()->find($draft->id, locale: 'en')->entry->title);

        foreach ([
            'edit_others_notes' => [
                fn () => Mainstay::drafts()->save(Note::class, ['title' => 'Taken'], draft: $theirs->id, locale: 'en'),
                fn () => Mainstay::drafts()->find($theirs->id, locale: 'en'),
                fn () => Mainstay::drafts()->discard($theirs->id),
            ],
            'edit_published_notes' => [fn () => Mainstay::drafts()->save(Note::class, ['title' => 'Changed'], entry: $live->id, locale: 'en')],
            'publish_notes' => [
                fn () => Mainstay::create(Note::class, ['title' => 'Direct', 'slug' => 'direct'], locale: 'en'),
                fn () => Mainstay::drafts()->publish($draft->id),
            ],
        ] as $needs => $calls) {
            foreach ($calls as $call) {
                $this->assertThrows($call, AuthorizationException::class, "This needs {$needs}.");
            }
        }

        $this->assertSame(['Theirs', 'Mine again'], DB::table('mainstay_drafts')->orderBy('id')->pluck('changes')->map(fn (string $changes) => json_decode($changes, true)['fields']['title'])->all());
        $this->assertSame([$live->id], Mainstay::find(Note::class, locale: 'en')->pluck('id')->all());
    }

    #[Test]
    public function a_new_entrys_draft_is_shown_to_gate_as_it_reads(): void
    {
        RecordingPolicy::$asked = [];
        HostGate::policy(Note::class, RecordingPolicy::class);

        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Mine'], locale: 'en');
        Mainstay::drafts()->save(Note::class, ['slug' => 'mine'], draft: $draft->id, locale: 'en');

        [$asked] = RecordingPolicy::$asked;
        $this->assertSame('Mine', $asked->title);
        $this->assertFalse(isset($asked->id), 'Not published, so no id.');
    }

    #[Test]
    public function a_write_straight_to_the_site_needs_publish(): void
    {
        $user = $this->user(['edit_notes', 'edit_published_notes', 'edit_banner']);
        $note = $this->note('own', $user->id);
        $this->signIn($user);

        $this->assertThrows(fn () => Mainstay::update(Note::class, $note->id, ['title' => 'Live'], locale: 'en'), AuthorizationException::class, 'This needs publish_notes.');
        $this->assertThrows(fn () => Mainstay::saveGlobal(Banner::class, ['text' => 'Live'], locale: 'en'), AuthorizationException::class, 'This needs publish_banner.');
        $this->assertNotNull(Mainstay::drafts()->save(Note::class, ['title' => 'Drafted'], entry: $note->id, locale: 'en'));
        $this->assertNotNull(Mainstay::drafts()->save(Banner::class, ['text' => 'Drafted'], locale: 'en'));
    }

    #[Test]
    public function restoring_from_the_trash_puts_an_entry_live_so_it_needs_publish(): void
    {
        $user = $this->user(['edit_notes', 'edit_published_notes', 'delete_notes']);
        $note = $this->note('trashed', $user->id);
        $this->signIn($user);

        Mainstay::delete(Note::class, $note->id);
        $this->assertThrows(fn () => Mainstay::restore(Note::class, $note->id), AuthorizationException::class, 'This needs publish_notes.');

        $user->update(['grants' => ['publish_notes']]);
        $this->signIn($user->fresh());
        $this->assertSame(['en' => '/notes/trashed'], Mainstay::restore(Note::class, $note->id));
    }

    #[Test]
    public function a_role_of_one_types_capabilities_reaches_that_type_alone(): void
    {
        $flyer = Mainstay::create(Flyer::class, ['title' => 'Flyer', 'slug' => 'flyer', 'memo' => 'Hidden'], locale: 'en', overrideAccess: true);
        $this->signIn($this->user([...Capabilities::of(Note::class), ...Capabilities::of(Banner::class)]));

        $note = Mainstay::create(Note::class, ['title' => 'Mine', 'slug' => 'mine', 'memo' => 'Seen'], locale: 'en');
        $this->assertSame('Seen', Mainstay::findById(Note::class, $note->id, locale: 'en')->memo);

        $read = Mainstay::findById(Flyer::class, $flyer->id, locale: 'en');
        $this->assertFalse((new ReflectionProperty($read, 'memo'))->isInitialized($read), "Another type's internal field is not shown.");

        $this->assertSame('Up', Mainstay::saveGlobal(Banner::class, ['text' => 'Up'], locale: 'en')->text);

        foreach ([
            'edit_flyers' => fn () => Mainstay::create(Flyer::class, ['title' => 'Other', 'slug' => 'other'], locale: 'en'),
            'edit_footer' => fn () => Mainstay::saveGlobal(Footer::class, ['text' => 'Down'], locale: 'en'),
            'manage_topics' => fn () => Mainstay::create(Topic::class, ['title' => 'Birds', 'slug' => 'birds'], locale: 'en'),
        ] as $needs => $call) {
            $this->assertThrows($call, AuthorizationException::class, "This needs {$needs}.");
        }
    }

    #[Test]
    public function terms_need_manage_and_someone_elses_image_needs_edit_others_media(): void
    {
        $this->signIn($user = $this->user(['manage_topics', 'upload_media']));

        $topic = Mainstay::create(Topic::class, ['title' => 'Birds', 'slug' => 'birds', 'memo' => 'Seen'], locale: 'en');
        $this->assertSame('Owls', Mainstay::update(Topic::class, $topic->id, ['title' => 'Owls'], locale: 'en')->title);
        $this->assertSame('Seen', Mainstay::findById(Topic::class, $topic->id, locale: 'en')->memo);
        Mainstay::delete(Topic::class, $topic->id);

        $mine = Mainstay::media()->upload($this->png(), alt: ['en' => 'Mine']);
        $this->assertSame($user->id, $mine->ownerId);
        $this->assertSame('Still mine', Mainstay::media()->update($mine->id, alt: ['en' => 'Still mine'])->alt);

        foreach ([$this->image($this->user(['*'])->id), $this->image(null)] as $id) {
            $this->assertThrows(fn () => Mainstay::media()->update($id, alt: ['en' => 'Taken']), AuthorizationException::class, 'This needs edit_others_media.');
        }

        $this->signIn($this->user(['upload_media']));
        $this->assertThrows(fn () => Mainstay::create(Topic::class, ['title' => 'Bats', 'slug' => 'bats'], locale: 'en'), AuthorizationException::class, 'This needs manage_topics.');
        Mainstay::restore(Topic::class, $topic->id, overrideAccess: true);
        $read = Mainstay::findById(Topic::class, $topic->id, locale: 'en');
        $this->assertFalse((new ReflectionProperty($read, 'memo'))->isInitialized($read), 'A term\'s internal field is for whoever manages its taxonomy.');
    }

    #[Test]
    public function the_editor_role_does_all_of_it_and_manages_no_accounts(): void
    {
        $editor = $this->user(role: 'editor');
        $other = $this->user(['edit_notes']);
        $theirs = $this->note('theirs', $other->id);

        $this->signIn($other);
        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Started', 'slug' => 'started'], locale: 'en');

        $this->signIn($editor);
        $this->assertSame('Check', Mainstay::findById(Note::class, $theirs->id, locale: 'en')->memo);
        $this->assertSame('Edited', Mainstay::update(Note::class, $theirs->id, ['title' => 'Edited'], locale: 'en')->title);
        $this->assertSame('Started', Mainstay::drafts()->publish($draft->id)->title);
        Mainstay::delete(Note::class, $theirs->id);
        Mainstay::restore(Note::class, $theirs->id);
        $this->assertSame('Down', Mainstay::saveGlobal(Footer::class, ['text' => 'Down'], locale: 'en')->text);
        $this->assertSame('Birds', Mainstay::create(Topic::class, ['title' => 'Birds', 'slug' => 'birds'], locale: 'en')->title);
        $this->assertSame('Taken', Mainstay::media()->update($this->image(null), alt: ['en' => 'Taken'])->alt);

        $this->assertFalse($editor->holds(Capabilities::USERS));
        $this->assertTrue($this->user(role: 'administrator')->holds(Capabilities::USERS));
    }

    #[Test]
    public function a_new_entry_is_owned_by_who_started_its_draft_and_code_on_its_own_authority_owns_nothing(): void
    {
        $author = $this->user(['edit_notes']);
        $editor = $this->user(role: 'editor');

        $this->signIn($author);
        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Started', 'slug' => 'started'], locale: 'en');

        $this->signIn($editor);
        $this->assertSame($author->id, Mainstay::drafts()->publish($draft->id)->ownerId);
        $this->assertSame($editor->id, Mainstay::create(Note::class, ['title' => 'Direct', 'slug' => 'direct'], locale: 'en')->ownerId);

        $this->signIn(null);
        $this->assertNull(Mainstay::create(Note::class, ['title' => 'Seeded', 'slug' => 'seeded'], locale: 'en', overrideAccess: true)->ownerId);
        $this->assertNull(Mainstay::media()->upload($this->png(), alt: ['en' => 'Seeded'], overrideAccess: true)->ownerId);
    }

    #[Test]
    public function the_hosts_gate_callbacks_are_never_handed_mainstays_user(): void
    {
        /* Typed for the host's own users, as a host writes them: handed
           Mainstay's, each would throw. */
        HostGate::before(fn (GenericUser $user) => true);
        HostGate::after(fn (GenericUser $user) => true);

        $this->signIn($this->user(['*']));
        $this->assertSame('Mine', Mainstay::create(Note::class, ['title' => 'Mine', 'slug' => 'mine'], locale: 'en')->title);

        $this->signIn($this->user());
        $this->assertThrows(fn () => Mainstay::create(Note::class, ['title' => 'Other', 'slug' => 'other'], locale: 'en'), AuthorizationException::class, 'This needs edit_notes.');
    }

    #[Test]
    public function signing_in_starts_mainstays_own_session_and_signing_out_ends_it(): void
    {
        $user = $this->user(role: 'editor');

        $this->assertFalse(Route::has('login'), 'The app has no login route of its own.');
        $guest = $this->browser()->get('/admin/collections/posts');
        $guest->assertRedirect(route('mainstay.login'));

        $jar = $this->keep([], $guest);
        $before = CookieValuePrefix::remove(decrypt($jar['mainstay_session'], false));
        $signedIn = $this->browser($jar)->post(route('mainstay.login'), ['email' => $user->email, 'password' => self::PASSWORD]);
        $signedIn->assertRedirect('/admin/collections/posts');
        $jar = $this->keep($jar, $signedIn);

        $this->assertNotSame($before, CookieValuePrefix::remove(decrypt($jar['mainstay_session'], false)), 'Signing in regenerates the session.');
        $this->assertArrayNotHasKey(config('session.cookie'), $jar, "The host's session is not started.");
        $this->assertArrayNotHasKey('XSRF-TOKEN', $jar, "One name and path for both sessions' tokens, so Mainstay sets none.");
        $this->assertSame('web', Auth::getDefaultDriver(), "The host's guard is the default again after the request.");
        $this->assertSame(config('session.cookie'), $this->app['session.store']->getName(), "The host's session name is back after the request.");

        /* Any path under it, which the client's router takes. */
        $this->browser($jar)->get('/admin/collections/posts')->assertOk()
            ->assertSee($user->name)
            ->assertSee('id="mainstay-editor"', escape: false)
            ->assertSee('aria-label="Sections"', escape: false);

        $signedIn = CookieValuePrefix::remove(decrypt($jar['mainstay_session'], false));
        $jar = $this->keep($jar, $this->browser($jar)->post(route('mainstay.logout')));
        $this->assertNotSame($signedIn, CookieValuePrefix::remove(decrypt($jar['mainstay_session'], false)), 'Signing out ends the session, not only the login in it.');
        $this->browser($jar)->get('/admin')->assertRedirect(route('mainstay.login'));
    }

    #[Test]
    public function what_the_host_defines_under_mainstays_names_is_kept(): void
    {
        config(['auth.passwords.mainstay' => [...config('auth.passwords.mainstay'), 'expire' => 15]]);
        config(['auth.guards' => Arr::except(config('auth.guards'), 'mainstay')]);

        (new MainstayServiceProvider($this->app))->register();

        $this->assertSame(15, config('auth.passwords.mainstay.expire'), "The host's broker is kept.");
        $this->assertSame(['driver' => 'session', 'provider' => 'mainstay'], config('auth.guards.mainstay'), 'What the host left out is added.');
    }

    #[Test]
    public function a_form_posted_to_the_admin_without_its_token_is_refused(): void
    {
        /* Outside tests, where Laravel's check would otherwise step aside. */
        $this->app['env'] = 'local';
        $user = $this->user(role: 'editor');

        $this->browser()->post(route('mainstay.login'), ['email' => $user->email, 'password' => self::PASSWORD])->assertStatus(419);

        $form = $this->browser()->get(route('mainstay.login'));
        preg_match('/name="_token" value="([^"]+)"/', $form->getContent(), $token);
        $this->browser($this->keep([], $form))->post(route('mainstay.login'), ['_token' => $token[1], 'email' => $user->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('mainstay.admin'));
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_email_are_one_answer_and_the_sixth_try_is_locked(): void
    {
        $user = $this->user(role: 'editor');
        $refused = 'That email and password do not match an account.';

        $this->browser()->post(route('mainstay.login'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors(['email' => $refused]);
        $this->browser()->post(route('mainstay.login'), ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertSessionHasErrors(['email' => $refused]);

        foreach ([strtoupper($user->email), ucfirst($user->email), $user->email, strtoupper($user->email)] as $email) {
            $this->browser()->post(route('mainstay.login'), ['email' => $email, 'password' => 'wrong'])->assertSessionHasErrors(['email' => $refused]);
        }

        $this->browser()->post(route('mainstay.login'), ['email' => $user->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts.', $this->stored('errors')->first('email'));

        /* Locked for that address, not for every one. */
        $this->browser()->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->post(route('mainstay.login'), ['email' => $user->email, 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function remember_me_signs_in_again_with_the_session_gone(): void
    {
        $user = $this->user(role: 'editor');

        $remembered = array_filter($this->login($user, remember: true), fn (string $name) => str_starts_with($name, 'remember_mainstay_'), ARRAY_FILTER_USE_KEY);
        $this->assertCount(1, $remembered);
        $this->browser($remembered)->get('/admin')->assertOk()->assertSee($user->name);

        $forgotten = array_filter($this->login($user), fn (string $name) => str_starts_with($name, 'remember_'), ARRAY_FILTER_USE_KEY);
        $this->assertSame([], $forgotten);
    }

    #[Test]
    public function the_layer_reads_as_the_signed_in_user_behind_the_admin_and_as_a_visitor_elsewhere(): void
    {
        $note = $this->note();
        $jar = $this->login($writer = $this->user([...Capabilities::of(Note::class)]), remember: true);

        $this->browser($jar)->post('stack/notes')->assertExactJson(['owner' => $writer->id]);
        $this->browser($jar)->get("stack/notes/{$note->id}")->assertExactJson(['memo' => 'Check']);
        $this->browser($jar)->get("public/notes/{$note->id}")->assertExactJson(['memo' => null]);
    }

    #[Test]
    public function a_forgotten_password_is_reset_through_a_mailed_link_and_other_sessions_end(): void
    {
        Notification::fake();
        $user = $this->user(role: 'editor');
        $sent = 'If that email has an account, a link to choose a new password is on its way.';

        /* Signed in on two other devices: one has been to the admin since, the
           other only signed in. */
        $device = $this->login($user);
        $device = $this->keep($device, $this->browser($device)->get('/admin')->assertOk());
        $waiting = $this->login($user, remember: true);
        $remembered = $user->fresh()->remember_token;

        foreach ([strtoupper($user->email), 'nobody@example.com', $user->email] as $email) {
            $this->browser()->post(route('mainstay.password.email'), ['email' => $email])->assertRedirect();
            $this->assertSame($sent, $this->stored('status'), "Answered alike for {$email}.");
            Notification::assertSentToTimes($user, ResetPassword::class, 1);
        }

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use ($user, &$token) {
            $token = $mail->token;

            return $mail->toMail($user)->actionUrl === route('mainstay.password.reset', ['token' => $mail->token, 'email' => $user->email]);
        });

        $this->browser()->get(route('mainstay.password.reset', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee($user->email);

        $reset = ['token' => $token, 'email' => $user->email, 'password' => 'a new long password', 'password_confirmation' => 'a new long password'];
        $this->browser()->post(route('mainstay.password.update'), $reset)->assertRedirect(route('mainstay.login'));
        $this->assertTrue(Hash::check('a new long password', $user->fresh()->password));

        $this->browser($device)->get('/admin')->assertRedirect(route('mainstay.login'));
        $this->browser($waiting)->get('/admin')->assertRedirect(route('mainstay.login'));
        $this->assertNotSame($remembered, $user->fresh()->remember_token, 'A remembered device is forgotten.');
        $this->browser()->post(route('mainstay.password.update'), [...$reset, 'password' => 'another', 'password_confirmation' => 'another'])->assertSessionHasErrors('password');
        $this->browser()->post(route('mainstay.password.update'), [...$reset, 'password' => 'yet another password', 'password_confirmation' => 'yet another password'])
            ->assertSessionHasErrors(['email' => 'This link has expired or been used. Ask for another.']);

        $this->browser($this->login($user, password: 'a new long password'))->get('/admin')->assertOk();
    }

    #[Test]
    public function a_link_older_than_an_hour_is_refused(): void
    {
        Notification::fake();
        $user = $this->user(role: 'editor');

        $this->browser()->post(route('mainstay.password.email'), ['email' => $user->email]);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });

        $this->travel(61)->minutes();

        $this->browser()->post(route('mainstay.password.update'), ['token' => $token, 'email' => $user->email, 'password' => 'a new long password', 'password_confirmation' => 'a new long password'])
            ->assertSessionHasErrors(['email' => 'This link has expired or been used. Ask for another.']);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    #[Test]
    public function mainstay_user_makes_an_account_and_refuses_what_it_cannot(): void
    {
        $this->browser()->get(route('mainstay.login'))->assertOk()->assertSee('php artisan mainstay:user');

        $this->artisan('mainstay:user', ['email' => 'Ada@Example.com', '--name' => 'Ada', '--role' => 'administrator'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password again', self::PASSWORD)
            ->assertSuccessful();

        $ada = User::query()->sole();
        $this->assertSame(['Ada', 'ada@example.com', 'administrator'], [$ada->name, $ada->email, $ada->role->name]);
        $this->assertTrue(Hash::check(self::PASSWORD, $ada->password));
        $this->browser()->get(route('mainstay.login'))->assertOk()->assertDontSee('mainstay:user');

        $this->artisan('mainstay:user', ['email' => 'ADA@example.com', '--role' => 'editor'])->expectsOutputToContain('already has an account')->assertFailed();
        $this->artisan('mainstay:user', ['email' => 'bo@example.com', '--role' => 'author'])->expectsOutputToContain('no role called "author"')->assertFailed();
        $this->artisan('mainstay:user', ['email' => 'bo@example.com'])->expectsOutputToContain('--role=administrator')->assertFailed();
        $this->artisan('mainstay:user', ['email' => 'bo@example.com', '--name' => 'Bo', '--role' => 'editor'])
            ->expectsQuestion('Password', self::PASSWORD)
            ->expectsQuestion('Password again', 'something else')
            ->assertFailed();
        $this->artisan('mainstay:user', ['email' => 'bo@example.com', '--name' => 'Bo', '--role' => 'editor'])
            ->expectsQuestion('Password', 'short')
            ->expectsQuestion('Password again', 'short')
            ->expectsOutputToContain('at least 8 characters')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }
}
