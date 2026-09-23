<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Content\Entry;

/*
 | What an entry lets a reader and a writer do, asked by the query layer on
 | every call. There is no Mainstay user until phase 9, so each method takes a
 | null one and answers for nobody: reading is open, what is internal is not,
 | and nobody writes. Code writing on its own authority -- a seeder, an import
 | -- says so with `overrideAccess: true`, which never reaches here.
 */
class EntryPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return false;
    }

    public function create(?object $user): Response
    {
        return $this->nobody();
    }

    public function update(?object $user, Entry $entry): Response
    {
        return $this->nobody();
    }

    public function delete(?object $user, Entry $entry): Response
    {
        return $this->nobody();
    }

    /* Named for the fix, because "This action is unauthorized" is what a
       seeder author reads first and it says nothing about what to change. */
    private function nobody(): Response
    {
        return Response::deny('Writing content needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.');
    }
}
