<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;

/*
 | What a global lets a reader and a writer do, as EntryPolicy does for an
 | entry. A global is one row per site that is never created or deleted as
 | far as anyone asking is concerned, so a save is always `update`, asked
 | about the type whether or not its row exists yet. Nobody writes until
 | phase 9; code writing on its own authority passes `overrideAccess: true`.
 */
class GlobalPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return false;
    }

    public function update(?object $user): Response
    {
        return $this->nobody();
    }

    public function publish(?object $user): Response
    {
        return $this->nobody();
    }

    private function nobody(): Response
    {
        return Response::deny('Writing a global needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.');
    }
}
