<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;

/*
 | What a global lets a reader and a writer do, as EntryPolicy does for an
 | entry. A global is one row per site that is never created or deleted as
 | far as anyone asking is concerned, and has no owner, so every question is
 | about the type: a save is `update`, whether or not its row exists yet.
 */
class GlobalPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function viewInternal(?User $user, string $type): bool
    {
        return (bool) $user?->holds(...Capabilities::names($type, 'edit'));
    }

    public function update(?User $user, string $type): Response
    {
        return $this->needs($user, $type, 'edit');
    }

    public function publish(?User $user, string $type): Response
    {
        return $this->needs($user, $type, 'publish');
    }

    private function needs(?User $user, string $type, string $verb): Response
    {
        return Capabilities::check(
            $user,
            'Writing a global needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.',
            Capabilities::names($type, $verb),
        );
    }
}
