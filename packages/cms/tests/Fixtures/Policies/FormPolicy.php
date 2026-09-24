<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A public form's: anyone may send and amend, nobody may read. */
class FormPolicy
{
    public function viewAny(?object $user): bool
    {
        return false;
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
}
