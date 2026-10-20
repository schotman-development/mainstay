<?php

namespace Mainstay\Auth;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Mainstay\Mainstay;

/*
 | Accounts and roles, written as the admin writes them: `Mainstay::accounts()
 | ->create('Ada', 'ada@example.com', role: 2)`. Every write asks
 | AccountPolicy, unless `overrideAccess` says the caller writes on its own
 | authority, and none leaves the installation with no account that can
 | manage accounts.
 */
final class Accounts
{
    private const ATTEMPTS = 3;

    public function __construct(private Mainstay $mainstay) {}

    /* An account with no password yet, which signs in as nothing until it
       chooses one from the link invite() mails it. */
    public function create(string $name, string $email, int $role, bool $overrideAccess = false): User
    {
        return $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate) use ($name, $email, $role) {
            $held = $roles->get($role) ?? throw ValidationException::withMessages(['role' => 'Choose a role.']);
            $gate?->authorize('create', [User::class, $held]);
            $email = Str::lower($email);
            $this->validate($users, null, ['name' => $name, 'email' => $email]);

            return User::query()->create(['name' => $name, 'email' => $email, 'role_id' => $held->id])->setRelation('role', $held);
        });
    }

    /*
     | What is given and nothing else. Its own name, email and password are
     | an account's to change, the email and the password only with the
     | current one; nobody chooses another's password, which is what the
     | invitation and "Forgot your password?" are for. A new password rotates
     | the remember token, as a reset does, so another device's remember
     | cookie signs nobody in.
     */
    public function update(int $id, ?string $name = null, ?string $email = null, ?int $role = null, #[\SensitiveParameter] ?string $password = null, #[\SensitiveParameter] ?string $current = null, bool $overrideAccess = false): User
    {
        return $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate, ?User $caller) use ($id, $name, $email, $role, $password, $current) {
            $user = $users->get($id) ?? throw new RecordNotFoundException("Account {$id} is not there to change.");
            $moved = $role === null || $role === (int) $user->role_id ? null : ($roles->get($role) ?? throw ValidationException::withMessages(['role' => 'Choose a role.']));
            $gate?->authorize('update', [$user, $moved]);
            $email = $email === null ? null : Str::lower($email);

            if ($gate !== null && $password !== null && ! $caller->is($user)) {
                throw new AuthorizationException('Only the account itself chooses its password. Send it an invitation, or it can use "Forgot your password?".');
            }

            if ($gate !== null && $caller->is($user) && ($password !== null || ($email !== null && $email !== $user->email)) && ! Hash::check((string) $current, (string) $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'That is not your current password.']);
            }

            $this->validate($users, $user->id, array_filter(['name' => $name, 'email' => $email, 'password' => $password], fn (?string $value) => $value !== null));

            $user->forceFill(array_filter(['name' => $name, 'email' => $email, 'role_id' => $moved?->id], fn (mixed $value) => $value !== null));

            if ($password !== null) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)]);
            }

            $user->save();

            return $user->setRelation('role', $moved ?? $user->role);
        });
    }

    /* Mails an account still without a password a link to choose one,
       after the read that allowed it, so a mailer that fails has written
       nothing and holds no lock. */
    public function invite(int $id, bool $overrideAccess = false): void
    {
        $user = $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate) use ($id) {
            $user = $users->get($id) ?? throw new RecordNotFoundException("Account {$id} is not there to invite.");
            $gate?->authorize('invite', $user);

            if ($user->password !== null) {
                throw ValidationException::withMessages(['invite' => "{$user->name} has chosen a password already. A forgotten one is reset from the sign-in page."]);
            }

            return $user;
        });

        $user->notify(new Invitation(Password::broker('mainstay')->createToken($user), $overrideAccess ? null : Gate::user()?->name));
    }

    /* Erased, and any link it was mailed with it. What it owns keeps an
       owner no account has, which reads as nobody. */
    public function delete(int $id, bool $overrideAccess = false): void
    {
        $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate) use ($id) {
            $user = $users->get($id) ?? throw new RecordNotFoundException("Account {$id} is not there to delete.");
            $gate?->authorize('delete', $user);

            DB::table('mainstay_password_reset_tokens')->where('email', $user->email)->delete();
            $user->delete();
        });
    }

    /*
     | A new role, or this one changed. Its name is held lowercased, as an
     | email is, since the unique index folds case on some drivers and not
     | on others. A capability no registered type derives is refused, unless
     | the role held it already: one a type no longer derives stays until
     | someone unchecks it. `*` is every capability, so it is all it holds.
     |
     | @param  list<string>  $capabilities
     */
    public function saveRole(?int $id, string $name, array $capabilities, bool $overrideAccess = false): Role
    {
        return $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate) use ($id, $name, $capabilities) {
            $role = $id === null ? new Role(['capabilities' => []]) : ($roles->get($id) ?? throw new RecordNotFoundException("Role {$id} is not there to change."));
            Validator::make(['capabilities' => $capabilities], ['capabilities' => ['array', 'list'], 'capabilities.*' => ['string']])->validate();
            $capabilities = array_values(array_unique($capabilities));
            $gate?->authorize('saveRole', [$role, $capabilities]);

            $name = Str::lower(trim($name));
            Validator::make(['name' => $name], ['name' => ['required', 'string', 'max:255']])->validate();

            if ($roles->contains(fn (Role $other) => $other->name === $name && $other->id !== $role->id)) {
                throw ValidationException::withMessages(['name' => "There is a role called \"{$name}\" already."]);
            }

            if (($unknown = array_diff($capabilities, ['*'], $this->mainstay->capabilities(), $role->capabilities)) !== []) {
                throw ValidationException::withMessages(['capabilities' => 'No registered type derives '.reset($unknown).'.']);
            }

            $role->fill(['name' => $name, 'capabilities' => in_array('*', $capabilities, true) ? ['*'] : $capabilities])->save();

            return $role;
        });
    }

    /* Only one nobody holds: what would the accounts holding it hold? */
    public function deleteRole(int $id, bool $overrideAccess = false): void
    {
        $this->locked($overrideAccess, function (Collection $users, Collection $roles, ?Gate $gate) use ($id) {
            $role = $roles->get($id) ?? throw new RecordNotFoundException("Role {$id} is not there to delete.");
            $gate?->authorize('deleteRole', $role);
            $holding = $users->where('role_id', $role->id)->count();

            if ($holding > 0) {
                throw ValidationException::withMessages(['role' => $holding === 1 ? 'An account holds this role. Give it another first.' : "{$holding} accounts hold this role. Give them another first."]);
            }

            $role->delete();
        });
    }

    /*
     | A write in a transaction that first reads every role and account with a
     | lock, so two administrators demoting each other wait for one another
     | and an older snapshot of a caller's own transaction is never what
     | decides; asks the policy of the caller as those rows have them; and is
     | refused where it takes away the last account that can manage
     | accounts -- one holding `manage_users` with a password, an invited one
     | being able to do nothing until it chooses one. Code on its own
     | authority may, since `mainstay:user` makes the next.
     */
    private function locked(bool $overrideAccess, Closure $write): mixed
    {
        $manages = fn (Collection $users) => $users->contains(fn (User $user) => $user->password !== null && $user->holds(Capabilities::USERS));

        return DB::transaction(function () use ($overrideAccess, $write, $manages) {
            [$users, $roles] = $this->read();
            $caller = $overrideAccess || ($id = Gate::user()?->getKey()) === null ? null : $users->get($id);
            $managed = $manages($users);
            $result = $write($users, $roles, $overrideAccess ? null : Gate::accounts()->forUser($caller), $caller);

            if (! $overrideAccess && $managed && ! $manages($this->read()[0])) {
                throw ValidationException::withMessages(['role' => 'That would leave no account that can manage accounts.']);
            }

            return $result;
        }, self::ATTEMPTS);
    }

    /** @return array{0: Collection<int, User>, 1: Collection<int, Role>} */
    private function read(): array
    {
        $roles = Role::query()->lockForUpdate()->get()->keyBy('id');
        $users = User::query()->lockForUpdate()->get()->keyBy('id')->each(fn (User $user) => $user->setRelation('role', $roles->get($user->role_id)));

        return [$users, $roles];
    }

    /* The fields given, an email unique among the other accounts as they
       were read under the lock. */
    private function validate(Collection $users, ?int $id, array $values): void
    {
        Validator::make($values, [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
            'password' => ['sometimes', 'required', PasswordRule::defaults()],
        ])->validate();

        if (isset($values['email']) && $users->contains(fn (User $other) => $other->email === $values['email'] && $other->id !== $id)) {
            throw ValidationException::withMessages(['email' => "{$values['email']} has an account already."]);
        }
    }
}
