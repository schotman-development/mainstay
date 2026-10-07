<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* An editor who publishes, and does not see what is internal. */
class PublisherPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return false;
    }

    public function create(?object $user): bool
    {
        return true;
    }

    public function update(?object $user, object $entry): bool
    {
        return true;
    }

    public function publish(?object $user, ?object $entry = null): bool
    {
        return true;
    }
}
