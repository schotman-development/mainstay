<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A public form's: anyone may create, nobody may read. */
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
}
