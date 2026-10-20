<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\Role;
use Mainstay\Auth\User;

/*
 | Who may change accounts and roles, asked by Mainstay::accounts() on every
 | write. Every ability needs `manage_users` but an account's own name, email
 | and password, and nobody hands out what they do not hold themselves: a
 | role is saved by someone covering what it held and what it will hold, an
 | account given a role by someone covering it, and an account changed by
 | someone covering what it holds -- so a `manage_users` holder who is not an
 | administrator cannot make one, or take one over by changing its email.
 */
class AccountPolicy
{
    public function viewAny(?User $user): Response
    {
        return $this->manages($user);
    }

    public function create(?User $user, Role $role): Response
    {
        return $this->manages($user, $role->capabilities);
    }

    /* Changing an account, and moving it to `$role` where one is given. Its
       own name, email and password are the account's own to change. */
    public function update(?User $user, User $account, ?Role $role = null): Response
    {
        if ($role === null && $user?->is($account)) {
            return Response::allow();
        }

        return $this->manages($user, $this->held($account), ...($role === null ? [] : [$role->capabilities]));
    }

    public function invite(?User $user, User $account): Response
    {
        return $this->manages($user, $this->held($account));
    }

    public function delete(?User $user, User $account): Response
    {
        if ($user?->is($account)) {
            return Response::deny('You cannot delete your own account.');
        }

        return $this->manages($user, $this->held($account));
    }

    /* A role as it is and as it will be: an empty one for a new role. */
    public function saveRole(?User $user, Role $role, array $capabilities): Response
    {
        return $this->manages($user, $role->capabilities ?? [], $capabilities);
    }

    public function deleteRole(?User $user, Role $role): Response
    {
        return $this->manages($user, $role->capabilities);
    }

    /** @return list<string> what an account holds that another could hand out */
    private function held(User $account): array
    {
        return [...$account->role->capabilities, ...$account->grants];
    }

    private function manages(?User $user, array ...$sets): Response
    {
        $response = Capabilities::check($user, 'Managing accounts needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.', [Capabilities::USERS]);

        foreach ($response->allowed() ? $sets : [] as $names) {
            if (($name = Capabilities::uncovered($user, $names)) !== null) {
                return Response::deny($name === '*' ? 'Only an administrator holding everything can do this.' : "This hands out {$name}, which you do not hold.");
            }
        }

        return $response;
    }
}
