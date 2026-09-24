<?php

namespace Mainstay\Tests\Fixtures\Policies;

/* A policy a host chose for a type, which reads nothing. */
class ClosedPolicy
{
    public function viewAny(?object $user): bool
    {
        return false;
    }
}
