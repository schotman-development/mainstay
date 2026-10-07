<?php

namespace Mainstay\Policies;

use Illuminate\Auth\Access\Response;
use Mainstay\Auth\Capabilities;
use Mainstay\Auth\User;
use Mainstay\Content\Entry;

/*
 | What a taxonomy's terms let a reader and a writer do. A term is written
 | live, with no draft, so every write is one capability, `manage`, whoever
 | owns the term. Tagging an article with one is the article's edit.
 */
class TermPolicy
{
    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function viewInternal(?User $user, string $type): bool
    {
        return (bool) $user?->holds(...Capabilities::names($type, 'manage'));
    }

    public function create(?User $user, string $type): Response
    {
        return $this->manages($user, $type);
    }

    public function update(?User $user, Entry $term): Response
    {
        return $this->manages($user, $term::class);
    }

    public function publish(?User $user, Entry|string $term): Response
    {
        return $this->manages($user, is_string($term) ? $term : $term::class);
    }

    public function delete(?User $user, Entry $term): Response
    {
        return $this->manages($user, $term::class);
    }

    public function restore(?User $user, Entry $term): Response
    {
        return $this->manages($user, $term::class);
    }

    public function forceDelete(?User $user, Entry $term): Response
    {
        return $this->manages($user, $term::class);
    }

    private function manages(?User $user, string $type): Response
    {
        return Capabilities::check(
            $user,
            'Writing content needs a Mainstay user, and there is none. Code that writes on its own authority passes overrideAccess: true.',
            Capabilities::names($type, 'manage'),
        );
    }
}
