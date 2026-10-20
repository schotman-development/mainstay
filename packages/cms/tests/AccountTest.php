<?php

namespace Mainstay\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Gate;
use Mainstay\Auth\Invitation;
use Mainstay\Auth\ResetPassword;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;
use Mainstay\Facades\Mainstay;
use Mainstay\Tests\Fixtures\Signed\Banner;
use Mainstay\Tests\Fixtures\Signed\Flyer;
use Mainstay\Tests\Fixtures\Signed\Note;
use Mainstay\Tests\Fixtures\Signed\Topic;
use PHPUnit\Framework\Attributes\Test;

/*
 | The phase 11 check: accounts and roles written through
 | Mainstay::accounts() -- invited by mail, given only what whoever gives
 | them holds, never leaving nobody to manage them -- the user's own
 | settings, and an entry given to another account.
 */
class AccountTest extends DatabaseTestCase
{
    private const PASSWORD = 'correct horse battery';

    private int $made = 0;

    /* Sessions in cookies, so a request sees what the browser sends and
       nothing the last request left on the server. */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'cookie');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->declare(Note::class, Flyer::class, Banner::class, Topic::class);
        $this->artisan('mainstay:sync')->assertSuccessful();
    }

    /* An account holding these capabilities through a role of its own, or
       holding the role named. */
    private function user(array $capabilities = [], array $grants = [], array $denials = [], ?string $role = null, bool $invited = false): User
    {
        $n = ++$this->made;
        $role = $role === null
            ? Role::query()->create(['name' => "role-{$n}", 'capabilities' => $capabilities])
            : Role::query()->where('name', $role)->sole();

        return User::query()->create([
            'name' => "Ada {$n}", 'email' => "ada-{$n}@example.com", 'password' => $invited ? null : self::PASSWORD,
            'role_id' => $role->id, 'grants' => $grants, 'denials' => $denials,
        ]);
    }

    /* The layer called as this user, as the admin's middleware leaves it. */
    private function signIn(?User $user): void
    {
        $this->app['request']->attributes->set(Gate::USER, $user);
    }

    private function role(string $name): int
    {
        return Role::query()->where('name', $name)->value('id');
    }

    /* Who could manage accounts, by id. */
    private function managers(): array
    {
        return User::query()->with('role')->get()->filter(fn (User $user) => $user->holds(Capabilities::USERS))->pluck('id')->sort()->values()->all();
    }

    /* The next request as a browser holding these cookies and nothing else:
       see IdentityTest::browser(). */
    private function browser(array $cookies = []): static
    {
        $this->app['auth']->forgetGuards();
        $this->app['session.store']->flush();
        $this->app['cookie']->flushQueuedCookies();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this->withUnencryptedCookies($cookies);
    }

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

    /* Only the remember cookie, as a browser has it once its session ended. */
    private function remembered(array $jar): array
    {
        return array_filter($jar, fn (string $name) => str_starts_with($name, 'remember_mainstay_'), ARRAY_FILTER_USE_KEY);
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

    /* A write beside a caller's transaction: what each driver does with it,
       as ContentTest::beside() has it. */
    private function beside(callable $write): bool
    {
        if (DB::getDriverName() !== 'sqlite') {
            $write();

            return true;
        }

        $this->assertThrows($write, QueryException::class, 'database is locked');

        return false;
    }

    #[Test]
    public function an_account_made_in_the_admin_is_invited_to_choose_its_password(): void
    {
        Notification::fake();
        $admin = $this->user(role: 'administrator');
        $jar = $this->login($admin);

        $this->browser($jar)->post(route('mainstay.users.store'), ['name' => 'Grace', 'email' => 'Grace@Example.com', 'role' => $this->role('editor')])->assertRedirect();
        $grace = User::query()->where('email', 'grace@example.com')->sole();
        $this->assertNull($grace->password, 'No password until it chooses one.');

        $token = null;
        Notification::assertSentTo($grace, Invitation::class, function (Invitation $mail) use ($grace, $admin, &$token) {
            $token = $mail->token;

            return $mail->from === $admin->name && $mail->toMail($grace)->actionUrl === route('mainstay.password.reset', ['token' => $mail->token, 'email' => 'grace@example.com']);
        });

        $this->browser()->post(route('mainstay.login'), ['email' => 'grace@example.com', 'password' => ''])->assertSessionHasErrors();
        $this->browser()->post(route('mainstay.login'), ['email' => 'grace@example.com', 'password' => 'anything at all'])->assertSessionHasErrors();

        /* Sent again while it has no password; the first link is spent. */
        $this->browser($jar)->post(route('mainstay.users.invite', $grace->id))->assertRedirect(route('mainstay.users.edit', $grace->id))->assertSessionHasNoErrors();
        Notification::assertSentToTimes($grace, Invitation::class, 2);
        $again = null;
        Notification::assertSentTo($grace, Invitation::class, function (Invitation $mail) use (&$again) {
            $again = $mail->token;

            return true;
        });

        $this->browser()->post(route('mainstay.password.update'), ['token' => $token, 'email' => 'grace@example.com', 'password' => 'her own long password', 'password_confirmation' => 'her own long password'])->assertSessionHasErrors('email');

        $chosen = ['token' => $again, 'email' => 'grace@example.com', 'password' => 'her own long password', 'password_confirmation' => 'her own long password'];
        $this->browser()->get(route('mainstay.password.reset', ['token' => $again, 'email' => 'grace@example.com']))->assertOk()->assertSee('Choose a password');
        $this->browser()->post(route('mainstay.password.update'), $chosen)->assertRedirect(route('mainstay.login'));
        $this->browser($this->login($grace->fresh(), password: 'her own long password'))->get('/admin')->assertOk()->assertSee('Grace');

        $this->signIn($admin);
        $this->assertArrayHasKey('invite', $this->refusal(fn () => Mainstay::accounts()->invite($grace->id)));
    }

    #[Test]
    public function an_invited_account_can_ask_for_a_link_itself(): void
    {
        Notification::fake();
        $invited = $this->user(role: 'editor', invited: true);

        $this->browser()->post(route('mainstay.password.email'), ['email' => $invited->email])->assertRedirect();

        Notification::assertSentTo($invited, ResetPassword::class);
    }

    #[Test]
    public function a_mailer_that_fails_leaves_the_account_made_and_says_so(): void
    {
        $this->app['config']->set('mail.default', 'smtp');
        $this->app['config']->set('mail.mailers.smtp', ['transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'timeout' => 1]);
        $jar = $this->login($this->user(role: 'administrator'));

        $response = $this->browser($jar)->post(route('mainstay.users.store'), ['name' => 'Grace', 'email' => 'grace@example.com', 'role' => $this->role('editor')]);

        $grace = User::query()->where('email', 'grace@example.com')->sole();
        $response->assertRedirect(route('mainstay.users.edit', $grace->id))->assertSessionHasErrors('invite');
    }

    #[Test]
    public function emails_and_role_names_are_unique_whatever_their_case_and_a_held_role_stays(): void
    {
        $admin = $this->user(role: 'administrator');
        $this->signIn($admin);

        $this->assertArrayHasKey('email', $this->refusal(fn () => Mainstay::accounts()->create('Other', strtoupper($admin->email), $this->role('editor'))));
        $this->assertArrayHasKey('name', $this->refusal(fn () => Mainstay::accounts()->saveRole(null, 'Editor', [])));

        $role = Mainstay::accounts()->saveRole(null, ' Proof Readers ', ['edit_notes']);
        $this->assertSame('proof readers', $role->name);
        $this->user(role: 'proof readers');
        $this->user(role: 'proof readers');

        $this->assertSame(['role' => ['2 accounts hold this role. Give them another first.']], $this->refusal(fn () => Mainstay::accounts()->deleteRole($role->id)));
        $this->assertArrayHasKey('capabilities', $this->refusal(fn () => Mainstay::accounts()->saveRole($role->id, 'proof readers', ['edit_ghosts'])));
    }

    #[Test]
    public function nobody_hands_out_what_they_do_not_hold(): void
    {
        $admin = $this->user(role: 'administrator');
        $manager = $this->user([Capabilities::USERS, ...Capabilities::of(Note::class), 'edit_entries']);
        $this->signIn($manager);

        $this->assertThrows(fn () => Mainstay::accounts()->update($admin->id, email: 'mine@example.com'), AuthorizationException::class, 'Only an administrator holding everything can do this.');
        $this->assertThrows(fn () => Mainstay::accounts()->saveRole($this->role('administrator'), 'administrator', ['edit_notes']), AuthorizationException::class);
        $this->assertThrows(fn () => Mainstay::accounts()->create('Nobody', 'nobody@example.com', $this->role('administrator')), AuthorizationException::class);
        $this->assertThrows(fn () => Mainstay::accounts()->saveRole(null, 'flyers', ['publish_flyers']), AuthorizationException::class, 'This hands out publish_flyers, which you do not hold.');
        $this->assertThrows(fn () => Mainstay::accounts()->saveRole(null, 'all of it', ['*']), AuthorizationException::class);
        $this->assertThrows(fn () => Mainstay::accounts()->invite($this->user(role: 'administrator', invited: true)->id), AuthorizationException::class, 'Only an administrator holding everything can do this.');
        $this->assertSame($admin->email, $admin->fresh()->email);

        /* What an account was granted counts as what it holds. */
        $granted = $this->user(['edit_notes'], grants: ['publish_flyers']);
        $this->assertThrows(fn () => Mainstay::accounts()->update($granted->id, name: 'Taken'), AuthorizationException::class, 'This hands out publish_flyers, which you do not hold.');

        /* A type's own name is held through its all-types form. */
        $flyers = Mainstay::accounts()->saveRole(null, 'flyer drafts', ['edit_flyers']);
        $writer = Mainstay::accounts()->create('Wren', 'wren@example.com', $flyers->id);
        $this->assertSame('flyer drafts', $writer->role->name);

        /* A name no type derives any longer gives nothing, so it costs nothing. */
        $stale = Role::query()->create(['name' => 'stale', 'capabilities' => ['edit_ghosts', 'edit_notes']]);
        $this->assertSame(['edit_ghosts'], Mainstay::accounts()->saveRole($stale->id, 'stale', ['edit_ghosts'])->capabilities);

        $this->assertThrows(fn () => Mainstay::accounts()->delete($manager->id), AuthorizationException::class, 'You cannot delete your own account.');
        Password::broker('mainstay')->createToken($writer);
        Mainstay::accounts()->delete($writer->id);
        $this->assertNull($writer->fresh());
        $this->assertFalse(DB::table('mainstay_password_reset_tokens')->where('email', $writer->email)->exists(), 'Its link goes with it.');
    }

    #[Test]
    public function a_denied_capability_is_not_handed_out_in_either_form(): void
    {
        $this->user(role: 'administrator');
        $editor = $this->user(role: 'editor', grants: [Capabilities::USERS], denials: ['edit_notes']);
        $this->signIn($editor);

        $this->assertThrows(fn () => Mainstay::accounts()->saveRole(null, 'every entry', ['edit_entries']), AuthorizationException::class, 'This hands out edit_entries, which you do not hold.');
        $this->assertThrows(fn () => Mainstay::accounts()->saveRole(null, 'notes', ['edit_notes']), AuthorizationException::class);
        $this->assertSame(['edit_flyers'], Mainstay::accounts()->saveRole(null, 'flyers', ['edit_flyers'])->capabilities);
        $this->assertThrows(fn () => Mainstay::accounts()->create('Wren', 'wren@example.com', $this->role('editor')), AuthorizationException::class);

        $this->signIn($this->user(role: 'administrator', denials: ['edit_notes']));
        $this->assertThrows(fn () => Mainstay::accounts()->create('Wren', 'wren@example.com', $this->role('administrator')), AuthorizationException::class, 'Only an administrator holding everything can do this.');
    }

    #[Test]
    public function the_last_account_that_can_manage_accounts_stays_able_to(): void
    {
        $admin = $this->user(role: 'administrator');
        $this->user(role: 'administrator', invited: true);
        $this->signIn($admin);

        $left = ['role' => ['That would leave no account that can manage accounts.']];
        $this->assertSame($left, $this->refusal(fn () => Mainstay::accounts()->update($admin->id, role: $this->role('editor'))), 'An invited administrator cannot sign in to manage anything.');
        $this->assertSame($left, $this->refusal(fn () => Mainstay::accounts()->saveRole($this->role('administrator'), 'administrator', ['edit_entries'])));
        $this->assertSame('administrator', $admin->fresh()->role->name);
        $this->assertSame(['*'], Role::query()->find($this->role('administrator'))->capabilities);

        $other = $this->user(role: 'administrator');
        Mainstay::accounts()->update($admin->id, role: $this->role('editor'));
        $this->assertSame([$other->id], array_values(array_diff($this->managers(), [User::query()->whereNull('password')->value('id')])));

        /* Code on its own authority may, since mainstay:user makes the next. */
        Mainstay::accounts()->update($other->id, role: $this->role('editor'), overrideAccess: true);
        $this->assertSame('editor', $other->fresh()->role->name);
    }

    #[Test]
    public function two_administrators_demoting_each_other_leave_one(): void
    {
        config()->set('database.connections.beside', config('database.connections.testing'));
        $first = $this->user(role: 'administrator');
        $second = $this->user(role: 'administrator');
        $editor = $this->role('editor');
        $this->signIn($first);

        /* The first's request runs in a transaction that read the accounts
           before the second demoted the first: an older snapshot must not
           be what lets the first demote the second. */
        DB::beginTransaction();
        DB::table('mainstay_users')->count();

        $raced = $this->beside(fn () => DB::connection('beside')->table('mainstay_users')->where('id', $first->id)->update(['role_id' => $editor]));

        try {
            $raced
                ? $this->assertThrows(fn () => Mainstay::accounts()->update($second->id, role: $editor), AuthorizationException::class, 'This needs manage_users.')
                : Mainstay::accounts()->update($second->id, role: $editor);
        } finally {
            DB::commit();
        }

        $this->assertSame([$raced ? $second->id : $first->id], $this->managers());
    }

    #[Test]
    public function a_user_changes_their_own_name_and_with_their_password_their_email_and_password(): void
    {
        $writer = $this->user(Capabilities::of(Note::class));
        $this->signIn($writer);

        $this->assertSame('Wren', Mainstay::accounts()->update($writer->id, name: 'Wren')->name);
        $this->assertSame(['current_password' => ['That is not your current password.']], $this->refusal(fn () => Mainstay::accounts()->update($writer->id, email: 'wren@example.com')));
        $this->assertSame(['current_password' => ['That is not your current password.']], $this->refusal(fn () => Mainstay::accounts()->update($writer->id, password: 'a new long password', current: 'wrong')));
        $this->assertSame('wren@example.com', Mainstay::accounts()->update($writer->id, email: 'Wren@Example.com', current: self::PASSWORD)->email);
        DB::table('mainstay_users')->where('id', $writer->id)->update(['remember_token' => 'remembered elsewhere']);
        Mainstay::accounts()->update($writer->id, password: 'a new long password', current: self::PASSWORD);
        $this->assertNotSame('remembered elsewhere', $writer->fresh()->remember_token, 'A remember cookie from before signs nobody in.');
        $this->assertThrows(fn () => Mainstay::accounts()->update($writer->id, role: $this->role('editor')), AuthorizationException::class, 'This needs manage_users.');

        $admin = $this->user(role: 'administrator');
        $this->signIn($admin);
        $this->assertThrows(fn () => Mainstay::accounts()->update($writer->id, password: 'chosen for them'), AuthorizationException::class);
    }

    #[Test]
    public function a_new_password_signs_out_every_other_device_and_keeps_this_one(): void
    {
        $writer = $this->user(Capabilities::of(Note::class));
        $other = $this->login($writer, remember: true);
        $this->browser($other)->get('/admin')->assertOk();
        $here = $this->login($writer, remember: true);

        $saved = $this->browser($here)->post(route('mainstay.account.update'), [
            'name' => $writer->name, 'email' => $writer->email, 'current_password' => self::PASSWORD,
            'password' => 'a new long password', 'password_confirmation' => 'a new long password',
        ]);
        $saved->assertRedirect(route('mainstay.account'))->assertSessionHasNoErrors();
        $here = $this->keep($here, $saved);
        $this->assertTrue(Hash::check('a new long password', $writer->fresh()->password));

        $this->browser($other)->get('/admin')->assertRedirect(route('mainstay.login'));
        $this->browser($this->remembered($other))->get(route('mainstay.login'))->assertOk()->assertSee('Remember me');
        $this->browser($this->remembered($other))->get('/admin')->assertRedirect(route('mainstay.login'));

        $this->browser($here)->get('/admin')->assertOk();
        $this->browser($this->remembered($here))->get('/admin')->assertOk();
    }

    #[Test]
    public function an_entry_and_a_draft_not_published_are_given_to_another_account(): void
    {
        $writer = $this->user(Capabilities::of(Note::class));
        $other = $this->user(Capabilities::of(Note::class));
        $editor = $this->user(role: 'editor');

        $this->signIn($writer);
        $draft = Mainstay::drafts()->save(Note::class, ['title' => 'Started', 'slug' => 'started'], locale: 'en');
        $this->assertSame($writer->id, Mainstay::drafts()->find($draft->id, locale: 'en')->entry->ownerId, 'A draft not published is owned by who started it.');

        $note = Mainstay::create(Note::class, ['title' => 'Live', 'slug' => 'live'], locale: 'en');
        Mainstay::update(Note::class, $note->id, ['title' => 'Live again'], locale: 'en');
        [$stamp, $revisions] = [DB::table('note')->where('id', $note->id)->value('updated_at'), DB::table('mainstay_revisions')->count()];
        $drafted = DB::table('mainstay_drafts')->where('id', $draft->id)->value('updated_at');

        $this->signIn($editor);
        Mainstay::assign(Note::class, $note->id, owner: $other->id);
        Mainstay::drafts()->assign($draft->id, owner: $other->id);

        $this->assertSame($other->id, Mainstay::findById(Note::class, $note->id, locale: 'en')->ownerId);
        $this->assertSame($other->id, Mainstay::drafts()->find($draft->id, locale: 'en')->entry->ownerId);
        $this->assertSame([$stamp, $revisions, $drafted], [DB::table('note')->where('id', $note->id)->value('updated_at'), DB::table('mainstay_revisions')->count(), DB::table('mainstay_drafts')->where('id', $draft->id)->value('updated_at')]);

        $flyers = $this->user(Capabilities::of(Flyer::class));
        $this->assertSame(['owner' => ["{$flyers->name} cannot write these, so cannot own one."]], $this->refusal(fn () => Mainstay::assign(Note::class, $note->id, owner: $flyers->id)));
        $this->assertArrayHasKey('owner', $this->refusal(fn () => Mainstay::assign(Note::class, $note->id, owner: 999)));
        $this->assertThrows(fn () => Mainstay::assign(Topic::class, 1, owner: $other->id), InvalidArgumentException::class);

        $changed = Mainstay::drafts()->save(Note::class, ['title' => 'Live, changed'], entry: $note->id, locale: 'en');
        $this->assertThrows(fn () => Mainstay::drafts()->assign($changed->id, owner: $editor->id), InvalidArgumentException::class, 'assign() the entry');
        $this->assertSame($other->id, Mainstay::findById(Note::class, $note->id, locale: 'en')->ownerId);
    }

    #[Test]
    public function giving_an_entry_away_needs_edit_others_and_what_changing_it_needs(): void
    {
        $owner = $this->user(['edit_notes', 'edit_published_notes', 'publish_notes']);
        $other = $this->user(Capabilities::of(Note::class));
        $this->signIn($owner);
        $note = Mainstay::create(Note::class, ['title' => 'Mine', 'slug' => 'mine'], locale: 'en');

        $this->assertThrows(fn () => Mainstay::assign(Note::class, $note->id, owner: $other->id), AuthorizationException::class, 'This needs edit_others_notes.');

        $this->signIn($this->user(['edit_others_notes']));
        $this->assertThrows(fn () => Mainstay::assign(Note::class, $note->id, owner: $other->id), AuthorizationException::class, 'This needs edit_notes.');

        $this->assertSame($owner->id, Mainstay::findById(Note::class, $note->id, locale: 'en')->ownerId);
    }
}
