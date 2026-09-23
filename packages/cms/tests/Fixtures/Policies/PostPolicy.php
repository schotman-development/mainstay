<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* Where Laravel's name-guessing looks for Post's policy: a host's own, for a
   model of its own that happens to share the name, typed for its own users. */
class PostPolicy
{
    public function viewAny(object $user): bool
    {
        return true;
    }
}
