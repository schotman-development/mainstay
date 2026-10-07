<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;
use Mainstay\Content\Entry;

/*
 | What an entry lets a reader and a writer do, asked by the query layer on
 | every call: WordPress's map_meta_cap, an ability asked about an entry
 | mapped to the capabilities of its type it needs, in either form. One
 | policy for every entry type, so a question about a type rather than an
 | entry is handed the type.
 |
 | Someone else's is anything whose owner is not the user, no owner included,
 | which is the answer that fails closed. Every row written before there were
 | users has none. An entry not published yet has no id, and the owner of
 | its draft.
 |
 | Without a user reading is open, what is internal is not, and nobody
 | writes. Code writing on its own authority -- a seeder, an import -- says
 | so with `overrideAccess: true`, which never reaches here.
 */
class EntryPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function viewInternal(?User $user, string $type): bool
    {
        return (bool) $user?->holds(...Capabilities::names($type, 'edit'));
    }

    public function create(?User $user, string $type): Response
    {
        return $this->needs($user, $type, 'edit');
    }

    public function update(?User $user, Entry $entry): Response
    {
        return $this->needs($user, $entry::class, 'edit', ...(isset($entry->id) ? ['edit_published'] : []), ...$this->others($user, $entry, 'edit_others'));
    }

    /* Putting a draft live: about the entry, or the type alone for a create
       that writes live directly. Its own ability, so a writer can be let
       draft and not publish. */
    public function publish(?User $user, Entry|string $entry): Response
    {
        return is_string($entry)
            ? $this->needs($user, $entry, 'publish')
            : $this->needs($user, $entry::class, 'publish', ...$this->others($user, $entry, 'edit_others'));
    }

    public function delete(?User $user, Entry $entry): Response
    {
        return $this->needs($user, $entry::class, 'delete', ...$this->others($user, $entry, 'delete_others'));
    }

    /* Back at its paths, which is putting it live again. */
    public function restore(?User $user, Entry $entry): Response
    {
        return $this->needs($user, $entry::class, 'delete', 'publish', ...$this->others($user, $entry, 'delete_others'));
    }

    public function forceDelete(?User $user, Entry $entry): Response
    {
        return $this->delete($user, $entry);
    }

    /** @return list<string> */
    private function others(?User $user, Entry $entry, string $verb): array
    {
        return $user !== null && ($entry->ownerId ?? null) === $user->getKey() ? [] : [$verb];
    }

    /* Named for the fix, because "This action is unauthorized" is what a
       seeder author reads first and it says nothing about what to change. */
    private function needs(?User $user, string $type, string ...$verbs): Response
    {
        return Capabilities::check(
            $user,
            'Writing content needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.',
            ...array_map(fn (string $verb) => Capabilities::names($type, $verb), $verbs),
        );
    }
}
