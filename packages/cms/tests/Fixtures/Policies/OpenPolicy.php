<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A policy a host chose for a type, which reads everything, internal fields
   included -- an answer neither a guessed policy nor Mainstay's own gives. */
class OpenPolicy
{
    public function viewAny(?object $user): bool
    {
        return true;
    }

    public function viewInternal(?object $user): bool
    {
        return true;
    }
}
