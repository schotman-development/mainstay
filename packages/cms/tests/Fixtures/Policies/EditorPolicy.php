<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* An editor's: reads and writes, and does not see what is internal. */
class EditorPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return false;
    }

    public function update(?object $user, object $entry): bool
    {
        return true;
    }
}
