<?php

namespace Mainstay\Tests\Fixtures\Policies;

use Illuminate\Auth\Access\Response;

/* A policy that refuses in its own words, and reads nothing. */
class OwnerPolicy
{
    public function viewAny(?object $user): bool
    {
        return false;
    }

    public function create(?object $user): Response
    {
        return Response::deny('New pages are closed until Monday.');
    }

    public function update(?object $user, object $entry): Response
    {
        return Response::deny('Only the owner may edit this page.');
    }

    public function delete(?object $user, object $entry): Response
    {
        return Response::denyAsNotFound('Only the owner may delete this page.');
    }
}
